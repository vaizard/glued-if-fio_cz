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
            $t->set("tz", $tz);
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

    private function transactions_remap() {
        $q = 'SELECT uuid, ext_data FROM t_settlement_transactions WHERE ext_schema = "fio_cz"';
        $res = $this->mysqli->execute_query($q, []);
        if (!$res) { throw new \Exception('No transactions found for remapping', 404); }
        $updateQuery = 'UPDATE t_settlement_transactions SET data = ? WHERE uuid = ?';
        $this->mysqli->begin_transaction();
        try {
            foreach ($res as $row) {
                $newData = $this->transactions_transform(json_decode($row['ext_data'],true));
                $this->mysqli->execute_query($updateQuery, [$newData, $row['uuid']]);
            }
            $this->mysqli->commit();   // Commit the changes if all updates are successful
        } catch (\Exception $e) {
            $this->mysqli->rollback(); // Rollback changes on error
            throw $e;                  // Rethrow the exception
        }
        return true;
    }

    public function act_r1(Request $request, Response $response, array $args = []): Response {
        if (($args['uuid'] ?? '') == 'transactions_remap') {
            return $response->withJson(['transactions_remap success' => $this->transactions_remap()]);
        }
        $token = $this->get_token($args['uuid'] ?? false);
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
