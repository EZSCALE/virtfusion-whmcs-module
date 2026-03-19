-- Insert records for Initial Operating System if they don't already exist
INSERT INTO tblcustomfields
(type, relid, fieldname, fieldtype, description, fieldoptions, regexpr, adminonly, required, showorder, showinvoice,
 sortorder, created_at, updated_at)
SELECT 'product',
       id,
       'Initial Operating System',
       'text',
       '',
       '',
       '',
       '',
       '',
       'on',
       '',
       0,
       UTC_TIMESTAMP(),
       UTC_TIMESTAMP()
FROM tblproducts
WHERE servertype = 'VirtFusionDirect'
  AND NOT EXISTS (SELECT 1
                  FROM tblcustomfields
                  WHERE fieldname = 'Initial Operating System'
                    AND relid = tblproducts.id);

-- Insert records for Initial SSH Key if they don't already exist
INSERT INTO tblcustomfields
(type, relid, fieldname, fieldtype, description, fieldoptions, regexpr, adminonly, required, showorder, showinvoice,
 sortorder, created_at, updated_at)
SELECT 'product',
       id,
       'Initial SSH Key',
       'text',
       '',
       '',
       '',
       '',
       '',
       'on',
       '',
       0,
       UTC_TIMESTAMP(),
       UTC_TIMESTAMP()
FROM tblproducts
WHERE servertype = 'VirtFusionDirect'
  AND NOT EXISTS (SELECT 1
                  FROM tblcustomfields
                  WHERE fieldname = 'Initial SSH Key'
                    AND relid = tblproducts.id);
