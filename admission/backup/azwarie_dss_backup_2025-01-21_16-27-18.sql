SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `ADMISSION`;
CREATE TABLE `ADMISSION` (
  `AdmissionID` varchar(12) NOT NULL,
  `AdmissionDateTime` timestamp NULL DEFAULT current_timestamp(),
  `DischargeDateTime` timestamp NULL DEFAULT current_timestamp(),
  `ReasonForAdmission` varchar(1000) NOT NULL,
  `BedID` varchar(12) NOT NULL,
  `PatientID` varchar(12) NOT NULL,
  `StaffID` varchar(12) NOT NULL,
  PRIMARY KEY (`AdmissionID`),
  KEY `BedID` (`BedID`),
  CONSTRAINT `ADMISSION_ibfk_1` FOREIGN KEY (`BedID`) REFERENCES `BED` (`BedID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A001','2025-01-18 21:12:25',NULL,'Intestinal Infections','B001','P001','N00001');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A002','2025-01-19 21:15:47',NULL,'Other Bacterial Infections','B002','P004','N00001');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A003','2025-01-19 21:17:42',NULL,'Digestive System Cancer','B003','P010','N00004');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A004','2025-01-19 21:18:00',NULL,'Other Cancer Types','B004','P011','N00004');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A005','2025-01-20 21:18:07',NULL,'Aplastic Anemia','B005','P013','N00004');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A006','2025-01-15 21:18:17',NULL,'Obesity and Overeating Disorders','B007','P015','N00004');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A007','2025-01-21 21:19:56',NULL,'Glaucoma','B008','P017','N00005');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A008','2025-01-21 21:20:02',NULL,'Bleeding Disorders','B009','P020','N00005');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A009','2025-01-22 21:20:07',NULL,'Parasitic Worm Infections','B012','P024','N00005');
INSERT INTO `ADMISSION` (`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('A010','2025-01-22 21:20:10',NULL,'Viral Fevers and Hemorrhagic Fevers','B013','P036','N00005');
DROP TABLE IF EXISTS `BED`;
CREATE TABLE `BED` (
  `BedID` varchar(12) NOT NULL,
  `BedStatus` varchar(100) NOT NULL,
  `DateAdded` timestamp NOT NULL DEFAULT current_timestamp(),
  `inventoryID` varchar(12) NOT NULL,
  PRIMARY KEY (`BedID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B001','occupied','2025-01-07 23:52:51','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B002','occupied','2025-01-07 23:54:14','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B003','occupied','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B004','occupied','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B005','occupied','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B006','maintenance','2025-01-20 19:51:05','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B007','occupied','2025-01-08 01:06:03','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B008','occupied','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B009','occupied','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B010','maintenance','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B011','maintenance','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B012','occupied','2025-01-08 01:06:12','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B013','occupied','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B014','maintenance','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B015','available','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B016','available','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B017','available','2025-01-08 01:06:17','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B018','available','2025-01-08 14:15:25','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B019','available','2025-01-08 16:40:20','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B020','maintenance','2025-01-08 16:46:23','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B021','available','2025-01-09 20:24:17','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B022','available','2025-01-13 18:42:31','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B023','available','2025-01-20 21:26:09','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B024','available','2025-01-20 21:26:09','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B025','available','2025-01-20 21:26:09','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B026','available','2025-01-20 21:26:09','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B027','available','2025-01-20 21:26:16','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B028','available','2025-01-20 21:26:16','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B029','available','2025-01-20 21:26:16','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B030','available','2025-01-20 21:26:16','INV004');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B031','available','2025-01-20 21:26:22','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B032','available','2025-01-20 21:26:22','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B033','available','2025-01-20 21:26:22','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B034','available','2025-01-20 21:26:22','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B035','available','2025-01-20 21:26:22','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B036','available','2025-01-20 21:26:22','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B037','available','2025-01-20 21:26:22','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B038','available','2025-01-20 21:26:22','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B039','available','2025-01-20 21:26:28','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B040','available','2025-01-20 21:26:28','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B041','available','2025-01-20 21:26:28','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B042','available','2025-01-20 21:26:28','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B043','available','2025-01-20 21:26:28','INV014');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B044','available','2025-01-20 21:26:44','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B045','available','2025-01-20 21:26:44','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B046','available','2025-01-20 21:26:44','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B047','available','2025-01-20 21:26:44','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B048','available','2025-01-20 21:26:44','INV002');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B049','available','2025-01-20 21:26:53','INV006');
INSERT INTO `BED` (`BedID`,`BedStatus`,`DateAdded`,`inventoryID`) VALUES ('B050','available','2025-01-20 21:26:53','INV006');
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
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125001','A016','2025-01-15 11:20:41','2025-01-18 10:38:38','Viral Fevers and Hemorrhagic Fevers','B021','P050','N00005');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125002','A015','2025-01-15 21:20:38','2025-01-19 13:38:41','Skin Infections and Disorders','B019','P048','N00005');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125003','A014','2025-01-06 11:20:34','2025-01-20 23:38:43','Lung Diseases','B018','P046','N00005');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125004','A013','2025-01-01 21:20:32','2025-01-22 23:38:46','Alzheimer and Other Degenerative Diseases','B017','P044','N00005');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125005','A012','2025-01-16 21:20:29','2025-01-19 23:38:48','Thyroid Disorders','B016','P042','N00005');
INSERT INTO `PAST_ADMISSION` (`PastAdmissionID`,`AdmissionID`,`AdmissionDateTime`,`DischargeDateTime`,`ReasonForAdmission`,`BedID`,`PatientID`,`StaffID`) VALUES ('PA0125006','A011','2025-01-12 14:20:27','2025-01-21 10:38:51','CNS Cancer (Brain, Eye)','B015','P039','N00005');
SET FOREIGN_KEY_CHECKS = 1;
