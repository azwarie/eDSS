SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `ADMISSION`;
CREATE TABLE `ADMISSION` (
  `AdmissionID` varchar(12) NOT NULL,
  `AdmissionDateTime` timestamp NULL DEFAULT current_timestamp(),
  `DischargeDateTime` timestamp NULL DEFAULT current_timestamp(),
  `ReasonForAdmission` varchar(100) NOT NULL,
  `BedID` varchar(12) NOT NULL,
  `PatientID` varchar(12) NOT NULL,
  `StaffID` varchar(12) NOT NULL,
  PRIMARY KEY (`AdmissionID`),
  KEY `BedID` (`BedID`),
  CONSTRAINT `ADMISSION_ibfk_1` FOREIGN KEY (`BedID`) REFERENCES `BED` (`BedID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A001','2025-01-16 16:14:24',NULL,'Viral Fevers and Hemorrhagic Fevers','B022','P050','N00002');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A002','2025-01-16 17:21:58',NULL,'Intestinal Infections','B021','P001','N00002');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A003','2025-01-16 17:56:00',NULL,'Skin Infections and Disorders','B019','P048','A00001');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A004','2025-01-17 16:26:09',NULL,'Lung Diseases','B018','P046','A00001');
DROP TABLE IF EXISTS `BED`;
CREATE TABLE `BED` (
  `BedID` varchar(12) NOT NULL,
  `BedStatus` varchar(100) NOT NULL,
  `DateAdded` timestamp NOT NULL DEFAULT current_timestamp(),
  `inventoryID` varchar(12) NOT NULL,
  PRIMARY KEY (`BedID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B001','available','2025-01-07 23:52:51','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B002','available','2025-01-07 23:54:14','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B003','available','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B004','available','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B005','available','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B006','available','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B007','available','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B008','available','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B009','available','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B010','maintenance','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B011','maintenance','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B012','available','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B013','available','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B014','maintenance','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B015','available','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B016','available','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B017','available','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B018','occupied','2025-01-08 14:15:25','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B019','occupied','2025-01-08 16:40:20','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B020','maintenance','2025-01-08 16:46:23','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B021','occupied','2025-01-09 20:24:17','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B022','occupied','2025-01-13 18:42:31','INV002');
DROP TABLE IF EXISTS `PAST_ADMISSION`;
CREATE TABLE `PAST_ADMISSION` (
  `PastAdmissionID` varchar(12) NOT NULL,
  `AdmissionID` varchar(12) NOT NULL,
  `AdmissionDateTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `DischargeDateTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `ReasonForAdmission` varchar(100) NOT NULL,
  `BedID` varchar(12) NOT NULL,
  `PatientID` varchar(12) NOT NULL,
  `StaffID` varchar(12) NOT NULL,
  PRIMARY KEY (`PastAdmissionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125001','A001','2024-12-30 16:25:27','2025-01-02 23:10:55','Tuber','B001','3','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125002','A001','2025-01-07 21:08:46','2025-01-07 21:10:03','Demam','B003','1','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125003','A002','2025-01-08 14:16:26','2025-01-08 14:16:41','Sakit Perut','B014','2','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125004','A002','2025-01-08 14:43:20','2025-01-08 14:44:00','Sakit perut','B012','2','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125005','A002','2025-01-09 20:25:28','2025-01-10 17:53:54','Gout','B021','2','N00002');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125006','A001','2025-01-13 18:38:02','2025-01-13 18:40:55','Viral Fevers and Hemorrhagic Fevers','B001','P050','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125007','A001','2025-01-13 18:44:30','2025-01-13 18:44:32','Viral Fevers and Hemorrhagic Fevers','B001','P050','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125008','A001','2025-01-13 18:45:19','2025-01-13 18:45:21','Viral Fevers and Hemorrhagic Fevers','B001','P050','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125009','A001','2025-01-13 18:51:14','2025-01-13 18:51:35','Viral Fevers and Hemorrhagic Fevers','B001','P050','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125010','A002','2025-02-02 22:27:11','2025-01-15 13:24:39','Pregnancy Complications','B003','P049','N00002');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125011','A002','2025-01-15 22:08:37','2025-01-15 22:11:52','','B002','','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125012','A002','2025-01-15 22:44:15','2025-01-15 22:48:38','Other Bacterial Infections','B022','P004','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125013','A002','2025-01-15 22:48:49','2025-01-15 22:52:57','Other Bacterial Infections','B022','P004','N00002');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125014','A003','2025-01-15 22:53:35','2025-01-15 22:53:38','Other Bacterial Infections','B022','P004','N00002');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125015','A002','2025-01-15 22:53:04','2025-01-15 22:53:42','Other Bacterial Infections','B022','P004','N00002');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125016','A003','2025-01-15 22:59:14','2025-01-15 23:00:40','Skin and Mucous Membrane Viral Infections','B021','P005','N00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125017','A002','2025-01-15 22:54:41','2025-01-15 23:06:02','Other Bacterial Infections','B022','P004','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125018','A002','2025-01-16 15:57:44','2025-01-16 16:13:24','Viral Fevers and Hemorrhagic Fevers','B022','P006','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125019','A001','2025-01-31 18:54:39','2025-01-16 16:13:29','Viral Fevers and Hemorrhagic Fevers','B001','P050','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125020','A001','2025-01-16 16:13:42','2025-01-16 16:14:13','Viral Fevers and Hemorrhagic Fevers','B022','P050','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125021','A002','2025-01-16 16:56:49','2025-01-16 16:57:40','Intestinal Infections','B021','P001','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125022','A002','2025-01-16 17:16:36','2025-01-16 17:21:29','Intestinal Infections','B001','P001','N00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA1224001','A001','2024-12-30 16:13:14','2024-12-30 16:13:38','Demam','B009','1','A00001');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA1224002','A001','2024-12-30 16:21:52','2024-12-30 16:22:56','Demam','B001','2','D00002');
SET FOREIGN_KEY_CHECKS = 1;
