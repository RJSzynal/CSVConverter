
-- Select the database

USE ebuyerTest;

-- Add the new rows

ALTER TABLE tblProductData ADD intStock INT(10) DEFAULT 0 AFTER strProductCode;

ALTER TABLE tblProductData ADD numCost NUMERIC(15,2) NOT NULL AFTER intStock;

