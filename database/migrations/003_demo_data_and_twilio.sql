-- Twilio WhatsApp support: a new channel type and the Twilio Account SID
ALTER TABLE channels MODIFY type ENUM('whatsapp','messenger','instagram','twilio_whatsapp') NOT NULL, ADD COLUMN account_sid VARCHAR(64) NULL;

-- Demo "customer ERP" data (served by /mock/erp, never touched by real tenants' own systems)
CREATE TABLE demo_products (
  sku VARCHAR(20) NOT NULL PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(60) NOT NULL,
  description VARCHAR(300) NULL,
  price DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'LKR',
  stock INT NOT NULL DEFAULT 0,
  variants VARCHAR(120) NULL,
  colors VARCHAR(120) NULL,
  cost_price DECIMAL(10,2) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE demo_orders (
  order_no VARCHAR(20) NOT NULL PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  customer_name VARCHAR(100) NULL,
  status ENUM('Processing','Packed','Shipped','Delivered','Cancelled') NOT NULL DEFAULT 'Processing',
  courier VARCHAR(40) NULL,
  tracking VARCHAR(40) NULL,
  eta VARCHAR(60) NULL,
  payment_method VARCHAR(30) NULL,
  delivery_city VARCHAR(60) NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL,
  internal_cost_note VARCHAR(200) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE demo_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(20) NOT NULL,
  sku VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  size VARCHAR(20) NULL,
  qty INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  KEY idx_order (order_no),
  CONSTRAINT fk_doi_order FOREIGN KEY (order_no) REFERENCES demo_orders(order_no) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO demo_products (sku, name, category, description, price, stock, variants, colors, cost_price) VALUES
('TS-001','Classic Cotton T-Shirt','T-Shirts','Soft 100% combed cotton, regular fit.',2490,42,'S,M,L,XL','White,Black,Navy',1100),
('TS-002','Sigiriya Graphic Tee','T-Shirts','Printed cotton tee with a Sigiriya rock design.',2990,25,'S,M,L,XL','White,Sand',1250),
('TS-003','Pique Polo Shirt','T-Shirts','Breathable pique polo with a two-button placket.',3790,18,'S,M,L,XL','Navy,Maroon,Grey',1700),
('SHT-101','Linen Casual Shirt','Shirts','Lightweight linen blend, ideal for hot days.',4990,12,'S,M,L,XL','White,Sky Blue',2300),
('SHT-102','Batik Print Shirt','Shirts','Hand-finished batik pattern, short sleeve.',4490,9,'M,L,XL','Indigo,Brown',2000),
('JN-014','Slim Fit Denim Jeans','Jeans & Trousers','Stretch denim, slim fit, mid rise.',6990,0,'30,32,34','Dark Blue',3100),
('JN-015','Straight Fit Jeans','Jeans & Trousers','Classic straight leg, durable denim.',6490,14,'30,32,34,36','Blue,Black',2900),
('TR-021','Cotton Chino Trousers','Jeans & Trousers','Smart casual chinos with a tapered leg.',5490,20,'30,32,34,36','Khaki,Navy,Olive',2400),
('SR-031','Cargo Shorts','Shorts','Six-pocket cotton cargo shorts.',3990,16,'30,32,34,36','Olive,Beige',1700),
('SH-221','Canvas Sneakers','Footwear','Lace-up canvas sneakers with a cushioned insole.',8450,17,'40,41,42,43','White,Black',3800),
('SH-222','Leather Slippers','Footwear','Genuine leather slip-ons, flexible sole.',3200,30,'39,40,41,42,43,44','Brown,Black',1400),
('SH-223','Running Shoes','Footwear','Lightweight mesh running shoes with grip sole.',12900,8,'40,41,42,43,44','Grey,Blue',6000),
('SH-224','Formal Oxford Shoes','Footwear','Polished leather oxfords for office wear.',14500,5,'40,41,42,43','Black,Brown',6800),
('BG-008','Leather Messenger Bag','Bags','Full-grain leather messenger with laptop sleeve.',12900,5,NULL,'Brown',5800),
('BG-009','Canvas Backpack','Bags','Water-resistant canvas backpack, 22L.',6900,22,NULL,'Olive,Black,Navy',3000),
('BG-010','Ladies Handbag','Bags','Structured faux-leather handbag with shoulder strap.',9800,11,NULL,'Tan,Black,Wine',4300),
('DR-301','Floral Summer Dress','Dresses','Light viscose dress with a floral print.',6200,13,'S,M,L','Blue Floral,Pink Floral',2700),
('DR-302','Casual Maxi Dress','Dresses','Comfortable cotton maxi with side pockets.',7400,7,'S,M,L,XL','Teal,Mustard',3200),
('SR-401','Kandyan Cotton Saree','Traditional','Handwoven cotton saree with a traditional border.',15900,4,NULL,'Maroon,Green',8000),
('SR-402','Handloom Cotton Sarong','Traditional','Soft handloom sarong, colourfast checks.',2800,35,NULL,'Blue Check,Green Check',1200),
('AC-501','Leather Belt','Accessories','Genuine leather belt with a brushed buckle.',2400,40,'S,M,L','Black,Brown',900),
('AC-502','Polarized Sunglasses','Accessories','UV400 polarized lenses, lightweight frame.',3500,0,NULL,'Black,Tortoise',1300),
('AC-503','Cotton Cap','Accessories','Adjustable cotton baseball cap.',1500,50,NULL,'Black,White,Navy',500),
('AC-504','Classic Wrist Watch','Accessories','Stainless steel quartz watch, water resistant.',11900,6,NULL,'Silver,Gold',5200);

INSERT INTO demo_orders (order_no, phone, customer_name, status, courier, tracking, eta, payment_method, delivery_city, subtotal, delivery_fee, total, internal_cost_note, created_at) VALUES
('ORD-1001','94771234567','Nimal Perera','Shipped','Domex','DX889120','Arrives in 2 days','Cash on delivery','Colombo 05',10940,0,10940,'margin 38%',NOW() - INTERVAL 2 DAY),
('ORD-1002','94770000000','Kamal Silva','Processing',NULL,NULL,'Dispatch in 1-2 days','Card','Kandy',12900,0,12900,'supplier backorder',NOW() - INTERVAL 1 DAY),
('ORD-1003','94771234567','Nimal Perera','Delivered','Pronto','PR55012','Delivered 6 days ago','Card','Colombo 05',8890,350,9240,'repeat customer',NOW() - INTERVAL 9 DAY),
('ORD-1004','94771234567','Nimal Perera','Processing',NULL,NULL,'Dispatch today','Cash on delivery','Colombo 05',6200,350,6550,NULL,NOW() - INTERVAL 3 HOUR),
('ORD-1005','94712345678','Dilani Fernando','Packed','Domex',NULL,'Pickup by courier today','Bank transfer','Galle',15900,0,15900,'high value',NOW() - INTERVAL 1 DAY),
('ORD-1006','94712345678','Dilani Fernando','Delivered','Koombiyo','KB901233','Delivered 3 weeks ago','Cash on delivery','Galle',3000,350,3350,NULL,NOW() - INTERVAL 22 DAY),
('ORD-1007','94777654321','Ruwan Jayasinghe','Shipped','Prompt Xpress','PX77120','Arrives tomorrow','Card','Negombo',12900,0,12900,NULL,NOW() - INTERVAL 3 DAY),
('ORD-1008','94777654321','Ruwan Jayasinghe','Cancelled',NULL,NULL,'Cancelled at customer request','Cash on delivery','Negombo',7580,350,7930,'customer changed mind',NOW() - INTERVAL 12 DAY),
('ORD-1009','94755500111','Sanduni Weerasinghe','Delivered','Koombiyo','KB445210','Delivered last week','Cash on delivery','Kurunegala',5600,350,5950,NULL,NOW() - INTERVAL 10 DAY),
('ORD-1010','94755500111','Sanduni Weerasinghe','Shipped','Domex','DX902155','Arrives in 3 days','Card','Kurunegala',7400,350,7750,NULL,NOW() - INTERVAL 1 DAY),
('ORD-1011','94766600222','Priya Nadarajah','Processing',NULL,NULL,'Dispatch in 1-2 days','Cash on delivery','Jaffna',5990,350,6340,NULL,NOW() - INTERVAL 5 HOUR),
('ORD-1012','94766600222','Priya Nadarajah','Delivered','Pronto','PR60877','Delivered 2 weeks ago','Card','Jaffna',9800,350,10150,NULL,NOW() - INTERVAL 16 DAY);

INSERT INTO demo_order_items (order_no, sku, name, size, qty, unit_price) VALUES
('ORD-1001','TS-001','Classic Cotton T-Shirt','M',1,2490),
('ORD-1001','SH-221','Canvas Sneakers','42',1,8450),
('ORD-1002','BG-008','Leather Messenger Bag',NULL,1,12900),
('ORD-1003','JN-015','Straight Fit Jeans','32',1,6490),
('ORD-1003','AC-501','Leather Belt','M',1,2400),
('ORD-1004','DR-301','Floral Summer Dress','M',1,6200),
('ORD-1005','SR-401','Kandyan Cotton Saree',NULL,1,15900),
('ORD-1006','AC-503','Cotton Cap',NULL,2,1500),
('ORD-1007','SH-223','Running Shoes','43',1,12900),
('ORD-1008','TS-003','Pique Polo Shirt','L',2,3790),
('ORD-1009','SR-402','Handloom Cotton Sarong',NULL,2,2800),
('ORD-1010','DR-302','Casual Maxi Dress','S',1,7400),
('ORD-1011','SHT-102','Batik Print Shirt','L',1,4490),
('ORD-1011','AC-503','Cotton Cap',NULL,1,1500),
('ORD-1012','BG-010','Ladies Handbag',NULL,1,9800);
