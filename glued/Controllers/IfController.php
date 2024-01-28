<?php

declare(strict_types=1);

namespace Glued\Controllers;
use JsonPath\JsonObject;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Selective\Transformer\ArrayTransformer;
use PHP_IBAN\IBAN;

class IfController extends AbstractController
{

    private $q;

    public function __construct(ContainerInterface $c)
    {
        parent::__construct($c);
    }



    private function transform($data)
    {
        $transformer = new ArrayTransformer();
        $transformer
            ->set('domicile', 'CZ')
            ->map('regid.val', 'ico')
            ->map('vatid.val', 'dic')
            ->map('name.0.val', 'obchodniJmeno')
            ->map('name.0.kind', 'obchodniJmeno',
                $transformer->rule()->callback(function ($v) { return 'business'; } ))
            ->map('address.0.kind', 'adresaDorucovaci.textovaAdresa',
                $transformer->rule()->callback(function ($v) { return 'business'; } ))
            ->map('address.0.val', 'sidlo.textovaAdresa')
            ->map('address.0.countrycode','sidlo.kodStatu')
            ->map('address.0.region', 'sidlo.nazevKraje')
            ->map('address.0.district', 'sidlo.nazevOkresu')
            ->map('address.0.municipality', 'sidlo.nazevObce')
            ->map('address.0.street', 'sidlo.nazevUlice')
            ->map('address.0.conscriptionnumber', 'sidlo.cisloDomovni')
            ->map('address.0.streetnumber', 'sidlo.cisloOrientacni')
            ->map('address.0.suburb', 'sidlo.nazevCastiObce')
            ->map('address.0.postcode', 'sidlo.psc')
            ->set('address.0.kind', 'business')
            ->map('address.1.val', 'adresaDorucovaci.textovaAdresa')
            ->map('address.1.kind', 'adresaDorucovaci.textovaAdresa',
                $transformer->rule()->callback(function ($v) { return 'forwarding'; } ))
            ->map('registration.0.date.establishing', 'datumVzniku')
            ->map('registration.0.date.update', 'datumAktualizace')
            ->map('registration.0.date.termination', 'datumZaniku')
            ->set('registration.0.kind', 'business');
        $data = json_decode($data, true);
        foreach ($data['ekonomickeSubjekty'] as $item) {
            $i = new JsonObject($item, true);
            $obj = $transformer->toArray($item);
            if ($i->{'$.dalsiUdaje.*.spisovaZnacka'}) { $obj['registration'][0]['val'] = $i->{'$.dalsiUdaje.*.spisovaZnacka'}[0]; }
            $objs[] = $obj;
        }
        return $objs;
    }

    private function fetch($token, $from, $to) :? array {
        $token = $token;
        $uri = "https://www.fio.cz/ib_api/rest/periods/{$token}/{$from}/{$to}/transactions.json";
        $headers = [ 'Content-Type: application/json' ];
        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if (curl_errno($ch)) { throw new \Exception(curl_error($ch)); }
        curl_close($ch);
        $data = json_decode($response, true);
        if (!$data) { throw new \Exception("Error: {$response}.", 400); }
        return $data;
    }
    private function fetch2(string $q) : mixed
    {
        $uri = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/vyhledat';
        $content = '{"obchodniJmeno":"' . $q . '","pocet":10,"start":0,"razeni":[]}';
        $key = hash('md5', $uri . $content);

        if ($this->fscache->has($key)) {
            $response = $this->fscache->get($key);
            $final = $this->transform($response);
            foreach ($final as &$f) {
                $fid = $f['regid']['val'];
                $f['save'] = $base = $this->settings['glued']['protocol'].$this->settings['glued']['hostname'].$this->settings['routes']['be_contacts_import_v1']['path'] . '/';
                $f['save'] .= "$this->action/$fid";
            }
            return $final;
        }

        try {
            if (mb_strlen($q, 'UTF-8') < 2) {
                throw new \Exception('Query string too short');
            }
            $client = new HttpBrowser(HttpClient::create(['timeout' => 20]));
            $client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:113.0) Gecko/20100101 Firefox/113.0');
            $client->setServerParameter('CONTENT_TYPE', 'application/json');
            $client->setServerParameter('HTTP_ACCEPT', 'application/json');
            $response = $client->request(method: 'POST', uri: $uri, parameters: [], files: [], server: [], content: $content);
        } catch (\Exception $e) {
            return null;
        }
        $response = $client->getResponse()->getContent() ?? null;
        $this->fscache->set($key, $response, 3600);
        $final = $this->transform($response);
        $stmt = $this->mysqli->prepare($this->q);
        foreach ($final as &$f) {
            $fid = $f['regid']['val'];
            $obj = json_encode($f);
            $run = NULL;
            $stmt->bind_param("ssss", $this->action, $fid, $obj, $run);
            $stmt->execute();
            $f['save'] = $base = $this->settings['glued']['protocol'].$this->settings['glued']['hostname'].$this->settings['routes']['be_contacts_import_v1']['path'] . '/';
            $f['save'] .= "$this->action/$fid";
        }


        //$this->fscache->set($key, $response, 3600);
        return $final;
    }

    private function get_token($action) {
        if (!$action) { throw new \Exception('Action UUID not provided', 400); }
        $r = (new \Glued\Lib\IfUtils($this->mysqli))->getAction($action);
        return $r['deployment_data']['auth']['key'] ?? false;
    }

    private function account_transform($ext_data)
    {
        $data['account']['iban'] = $ext_data['iban'];
        $data['account']['id'] = $ext_data['accountId'];
        $data['account']['currency'] = $ext_data['currency'];
        $data['bank']['bic'] = $ext_data['bic'];
        $data['bank']['id'] = $ext_data['bankId'];
        $data['bank']['name'] = 'Fio banka, a.s.';
        $data['bank']['address'] = 'V Celnici 1028/10, 117 21, Praha 1, Czech republic';
        $data['account']['kind'] = 'bank';
        return json_encode($data);
    }
    private function account($data) {
        $d = $data['accountStatement']['info'] ?? false;
        if (!$d) { throw new \Exception('Account data missing', 500); }
        $q = "INSERT INTO t_settlement_accounts (data, ext_schema, ext_data, ext_fid)
              VALUES (?, ?, ?, ?) ON DUPLICATE KEY
              UPDATE data = VALUES(data), ext_data = VALUES(ext_data)";
        $this->mysqli->execute_query($q, [
            $this->account_transform($d),
            "fio_cz",
            json_encode($d),
            "iban:{$d['iban']}"
        ]);
        $q = "SELECT bin_to_uuid(uuid, true) as uuid
              FROM t_settlement_accounts WHERE ext_fid = ? LIMIT 1";
        $res = $this->mysqli->execute_query($q, ["iban:{$d['iban']}"]);
        foreach ($res as $i) {
            return $i['uuid'] ?? false;
        }
        return false;
    }

    private function transactions_transform($ext_data)
    {

        $t = new ArrayTransformer();
        if ($ext_data['column22']['value'] ?? false) { $t->set("uri", "trx:fio_cz/{$ext_data['column22']['value']}"); }
        $t->map("id", "column22.value");

        if ($ext_data['column0']['value'] ?? false) {
            $dt = substr($ext_data['column0']['value'], 0, 10); // Extracts "2023-11-14"
            $tz = substr($ext_data['column0']['value'], 10);
            $t->set("at", $dt);
            $t->set("tz", $dt);
        }
        //$t->map("at", "column0.value");
        $t->map("volume", "column1.value");
        $t->map("currency", "column14.value");
        $ibanObj = new IBAN($ext_data['column2']['value'] ?? '');
        if ($ibanObj->Verify()) { $t->map("who.account.iban", "column2.value"); }
        else { $t->map("who.account.id", "column2.value"); }
        if (($ext_data['column10']['value'] ?? "") != "") { $t->map("who.account.name", "column10.value"); }
        $t->map("who.bank.id", "column3.value");
        $t->map("who.bank.name", "column12.value");
        $t->map("order.id", "column17.value");
        $t->map("order.by", "column9.value");
        $t->map("message", "column16.value"); // zprava (prijata i odeslana)
        $t->map("note", "column25.value"); // poznamka
        // reference platci / vs
        if ($ext_data['column27']['value'] ?? false) { $t->map("reference", "column27.value"); }
        else { $t->map("reference", "column5.value"); }
        $t->map("meta.vs", "column5.value");
        $t->map("meta.ks", "column4.value");
        $t->map("meta.ss", "column6.value");
        $t->map("meta.type", "column8.value"); // bezhotovostni platba
        $t->map("who.bank.bic", "column26.value");
        $data = $t->toArray($ext_data);
        return json_encode($data);
    }
    private function transactions($account, $data) {
        if (!$account) { throw new \Exception('Account UUID missing', 500); }
        $d = $data['accountStatement']['transactionList']['transaction'] ?? false;
        if (!$d) { throw new \Exception('Transaction data missing', 500); }
        $q = "INSERT INTO t_settlement_transactions (account, data, ext_schema, ext_data, ext_fid)
              VALUES (uuid_to_bin(?, true), ?, ?, ?, ?) ON DUPLICATE KEY
              UPDATE data = VALUES(data), ext_data = VALUES(ext_data)";
        foreach ($d as $i) {
            $this->mysqli->execute_query($q, [
                $account,
                $this->transactions_transform($i),
                "fio_cz",
                json_encode($i),
                "trx:fio_cz/{$i['column22']['value']}"
            ]);
        }
    }

    public function act_r1(Request $request, Response $response, array $args = []): Response {
        $token =  $this->get_token($args['uuid'] ?? false);
        $from = date('Y-m-d', strtotime($args['from'] ?? '-80 days'));
        $to = date('Y-m-d');
        $data = $this->fetch($token, $from, $to);
        $account_uuid = $this->account($data);
        $this->transactions($account_uuid, $data);
        return $response->withJson($data);
    }



    public function docs_r1(Request $request, Response $response, array $args = []): Response {
        return $response->withJson($args);
    }




}
