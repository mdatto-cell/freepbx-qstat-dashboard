-- phpMyAdmin SQL Dump
-- version 4.9.7
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Окт 07 2026 г., 15:21
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
-- Структура таблицы `cc_outbound_calls`
--

CREATE TABLE `cc_outbound_calls` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `system_id` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pbx1',
  `call_time` datetime NOT NULL,
  `callid` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cdr_sequence` int(11) NOT NULL DEFAULT '0',
  `did_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wait_seconds` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `talk_seconds` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `recording_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `cc_outbound_calls`
--
ALTER TABLE `cc_outbound_calls`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_callid_seq` (`callid`,`cdr_sequence`),
  ADD KEY `idx_call_time` (`call_time`),
  ADD KEY `idx_client` (`client_number`),
  ADD KEY `idx_agent` (`agent_number`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `cc_outbound_calls`
--
ALTER TABLE `cc_outbound_calls`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
