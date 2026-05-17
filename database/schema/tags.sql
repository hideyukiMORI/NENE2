-- tags table schema
-- Migration: 20260516000001_create_tags_table
CREATE TABLE `tags` (
  `id`   int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
