-- Meta Lead Ads Entegrasyonu için Veritabanı Güncellemesi
-- Bu SQL'i MySQL/phpMyAdmin'de çalıştırın

-- 1. fb_leadgen_id kolonu ekle (Meta'dan gelen unique ID)
ALTER TABLE leads ADD COLUMN IF NOT EXISTS fb_leadgen_id VARCHAR(64) NULL DEFAULT NULL;

-- 2. lead_source kolonu ekle (website vs meta_lead_ads ayrımı)
ALTER TABLE leads ADD COLUMN IF NOT EXISTS lead_source ENUM('website', 'meta_lead_ads') DEFAULT 'website';

-- 3. email kolonu ekle (eğer yoksa - Meta formlarında genelde email olur)
ALTER TABLE leads ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL DEFAULT NULL;

-- 4. fb_leadgen_id için index ekle (duplicate kontrolü için)
CREATE INDEX IF NOT EXISTS idx_fb_leadgen_id ON leads (fb_leadgen_id);

-- NOT: Bazı MySQL versiyonlarında "IF NOT EXISTS" çalışmayabilir.
-- Bu durumda önce kolonun var olup olmadığını kontrol edin:
-- SHOW COLUMNS FROM leads LIKE 'fb_leadgen_id';
