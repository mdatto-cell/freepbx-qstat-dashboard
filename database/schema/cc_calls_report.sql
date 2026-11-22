-- Safe initial-install schema: no DROP TABLE.
-- Run only for a new database or absent tables; existing tables are not migrated.
-- MySQL dump 10.14  Distrib 5.5.65-MariaDB, for Linux (x86_64)
--
-- Host: localhost    Database: asteriskcdrdb
-- ------------------------------------------------------
-- Server version	5.5.65-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `cc_calls_report`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cc_calls_report` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_id` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pbx1',
  `queue_log_id` bigint(20) unsigned DEFAULT NULL,
  `call_time` datetime NOT NULL,
  `callid` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `did_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `queue_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_ext` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wait_seconds` int(10) unsigned NOT NULL DEFAULT '0',
  `talk_seconds` int(10) unsigned NOT NULL DEFAULT '0',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `callback_time` datetime DEFAULT NULL,
  `callback_agent_ext` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `callback_disposition` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linkedid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recording_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recording_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `debug_uniqueid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `debug_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `client_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_system_callid` (`system_id`,`callid`),
  KEY `idx_call_time` (`call_time`),
  KEY `idx_status_time` (`status`,`call_time`),
  KEY `idx_did_time` (`did_number`,`call_time`),
  KEY `idx_queue_time` (`queue_number`,`call_time`),
  KEY `idx_client_time` (`client_number`,`call_time`),
  KEY `idx_agent_time` (`agent_ext`,`call_time`)
) ENGINE=InnoDB AUTO_INCREMENT=28954 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-22 18:59:30
