-- migrate:up

INSERT INTO `t_if__services` (`c_uuid`, `c_data`)
VALUES (uuid_to_bin("a3d3e577-b2d3-45af-b2c3-e6cb32e49903", true), '{"remote": "https://fio.cz", "service": "fio_cz", "deployment": "fio.cz"}')
ON DUPLICATE KEY UPDATE `c_data` = VALUES(`c_data`);

-- migrate:down

