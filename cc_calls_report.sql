-- phpMyAdmin SQL Dump
-- version 4.9.7
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Окт 07 2026 г., 15:19
-- Версия сервера: 5.5.65-MariaDB
-- Версия PHP: 5.6.40

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `asteriskcdrdb`
--

-- --------------------------------------------------------

--
-- Структура таблицы `cc_calls_report`
--

CREATE TABLE `cc_calls_report` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `system_id` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pbx1',
  `queue_log_id` bigint(20) UNSIGNED DEFAULT NULL,
  `call_time` datetime NOT NULL,
  `callid` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `did_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `queue_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_ext` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wait_seconds` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `talk_seconds` int(10) UNSIGNED NOT NULL DEFAULT '0',
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
  `client_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `cc_calls_report`
--
ALTER TABLE `cc_calls_report`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_callid` (`system_id`,`callid`),
  ADD KEY `idx_call_time` (`call_time`),
  ADD KEY `idx_status_time` (`status`,`call_time`),
  ADD KEY `idx_did_time` (`did_number`,`call_time`),
  ADD KEY `idx_queue_time` (`queue_number`,`call_time`),
  ADD KEY `idx_client_time` (`client_number`,`call_time`),
  ADD KEY `idx_agent_time` (`agent_ext`,`call_time`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `cc_calls_report`
--
ALTER TABLE `cc_calls_report`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
