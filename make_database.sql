

-- Create database

CREATE DATABASE ebuyerTest;

-- and use...

USE ebuyerTest;

-- Create table for data

CREATE TABLE tblProductData (
  intProductDataId int(10) unsigned NOT NULL AUTO_INCREMENT,
  strProductName varchar(50) NOT NULL,
  strProductDesc varchar(255) NOT NULL,
  strProductCode varchar(10) NOT NULL,
  dtmAdded datetime DEFAULT NULL,
  dtmDiscontinued datetime DEFAULT NULL,
  stmTimestamp timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (intProductDataId),
  UNIQUE KEY (strProductCode)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='Stores product data';

-- Create a user for access

CREATE USER 'ebuyer'@'localhost' IDENTIFIED BY 'testPassword';
CREATE USER 'ebuyer'@'%' IDENTIFIED BY 'testPassword';

-- Grant user correct permissions

GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,DROP
  ON ebuyerTest.*
  TO 'ebuyer'@'%';