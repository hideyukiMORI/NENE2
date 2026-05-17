-- notes table schema
-- Migration: 20260516000000_create_notes_table
CREATE TABLE `notes` (
  `id`    int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `body`  text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
