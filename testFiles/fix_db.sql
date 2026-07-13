CREATE TABLE IF NOT EXISTS item_prices (
  id int NOT NULL AUTO_INCREMENT,
  item_id int NOT NULL,
  business_id int NOT NULL,
  currency varchar(10) NOT NULL,
  cost_price decimal(12,2) NOT NULL,
  sale_price decimal(12,2) NOT NULL,
  valid_from date NOT NULL,
  valid_to date DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_item_prices_item_valid (item_id,valid_from,valid_to),
  KEY fk_item_prices_business (business_id),
  KEY fk_item_prices_items (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS supplier_item_prices (
  id int NOT NULL AUTO_INCREMENT,
  supplier_id int NOT NULL,
  item_id int NOT NULL,
  tenant_id int NOT NULL,
  last_price decimal(12,2) NOT NULL,
  avg_price decimal(12,2) NOT NULL,
  min_price decimal(12,2) NOT NULL,
  max_price decimal(12,2) NOT NULL,
  last_purchase_date date NOT NULL,
  purchase_count int NOT NULL DEFAULT 1,
  is_preferred tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_supplier_item_tenant (supplier_id, item_id, tenant_id),
  KEY fk_supplier_item_prices_supplier (supplier_id),
  KEY fk_supplier_item_prices_item (item_id),
  KEY fk_supplier_item_prices_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
