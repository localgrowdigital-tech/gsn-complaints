-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 29, 2026 at 03:43 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gsn_complaints`
--

-- --------------------------------------------------------

--
-- Table structure for table `complaints`
--

CREATE TABLE `complaints` (
  `id` int(11) NOT NULL,
  `complaint_id` varchar(30) NOT NULL,
  `complaint_date` date NOT NULL,
  `tracking_number` varchar(50) NOT NULL,
  `secondary_tracking_number` varchar(50) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `address` text DEFAULT NULL,
  `complaint_type` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(30) DEFAULT 'Open',
  `closing_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `complaints`
--

INSERT INTO `complaints` (`id`, `complaint_id`, `complaint_date`, `tracking_number`, `secondary_tracking_number`, `customer_name`, `mobile`, `address`, `complaint_type`, `description`, `status`, `closing_date`, `created_at`) VALUES
(1, 'GSN24079', '2026-06-28', 'GSN7000465201', '', 'Sameer Sayyed', '08169099517', 'kajupada pipe line kurla west\r\nshop no 2 J.P. Chawl, Near Prajapati Hall', 'Wrong Delivery', 'PLEASE SHARE STATUS', 'Open', NULL, '2026-06-29 10:47:24');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `complaints`
--
ALTER TABLE `complaints`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `complaints`
--
ALTER TABLE `complaints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
