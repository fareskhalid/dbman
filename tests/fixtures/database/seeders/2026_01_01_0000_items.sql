START TRANSACTION;
INSERT INTO `items` (`id`, `name`) VALUES (1, 'عنصر تجريبي');
INSERT INTO `notes` (`id`, `item_id`, `body`) VALUES (1, 1, 'A quoted ; semicolon and COMMIT; text');
COMMIT;
