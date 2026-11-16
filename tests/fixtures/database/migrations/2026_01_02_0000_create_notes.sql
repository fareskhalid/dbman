CREATE TABLE `notes` (
    `id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `body` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `notes_item_fk` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
