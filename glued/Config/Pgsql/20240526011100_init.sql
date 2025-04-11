-- migrate:up

INSERT INTO "if__deployments" ("doc")
VALUES ('{"uuid":"a3d3e577-b2d3-45af-b2c3-e6cb32e49903","service":"fio_cz","name":"fio.cz","description":"Fio.cz banking connector","interfaces":[{"connector":"api","id":"your id such as account number", "token":"your token"}]}')
ON CONFLICT ("uuid") DO UPDATE SET
    "doc" = EXCLUDED."doc";

-- migrate:down

DELETE FROM "if__deployments"
WHERE "uuid" = 'a3d3e577-b2d3-45af-b2c3-e6cb32e49903';


