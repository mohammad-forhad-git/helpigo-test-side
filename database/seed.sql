-- Starting data: settings, launch zones and service categories. No user accounts
-- (create the first admin with: php bin/create-admin.php). Prices are pilot assumptions, editable by admin.

INSERT INTO site_settings (setting_key, setting_value) VALUES
('site_name', 'Helpigo'),
('site_tagline', 'Your Task, Our People.'),
('support_phone', ''),
('support_email', ''),
('bkash_number', ''),
('nagad_number', ''),
('platform_fee', '15.00'),
('commission_rate', '0.1500'),
('task_prefix', 'HG'),
('open_task_expiry_minutes', '60'),
('auto_complete_hours', '24'),
('dispute_window_hours', '24'),
('refund_hours', '48'),
('float_tier1', '1000.00'),
('float_tier2', '3000.00'),
('float_tier3', '5000.00'),
('max_worker_dues', '500.00'),
('min_payout', '100.00'),
('payout_weekday', '0'),
('cancel_fee_assigned', '30.00'),
('strike_limit', '3'),
('first_task_discount', '50.00'),
('referral_credit', '50.00'),
('new_customer_max_active', '2'),
('service_hours', '8:00 AM – 10:00 PM');

INSERT INTO zones (name, name_bn, slug, sort_order) VALUES
('Mohammadpur', 'মোহাম্মদপুর', 'mohammadpur', 1),
('Dhanmondi', 'ধানমন্ডি', 'dhanmondi', 2),
('Lalmatia', 'লালমাটিয়া', 'lalmatia', 3);

INSERT INTO categories (code, name, name_bn, description, icon, pricing_mode, base_fee, included_items, per_item_fee, involves_purchase, needs_dropoff, allows_prescription, sort_order) VALUES
('medicine', 'Medicine collection', 'ওষুধ সংগ্রহ', 'Buy medicine from a licensed pharmacy. Prescription photo needed for prescription medicines.', 'pill', 'fixed', 80.00, 0, 0.00, 1, 1, 1, 1),
('grocery', 'Grocery shopping', 'মুদি বাজার', 'Your shopping list from the shop you choose. Up to 15 items included.', 'basket', 'fixed', 100.00, 15, 3.00, 1, 1, 0, 2),
('fresh_market', 'Fresh-market shopping', 'কাঁচা বাজার', 'Vegetables, fruit, fish and meat from the local market.', 'leaf', 'fixed', 150.00, 0, 0.00, 1, 1, 0, 3),
('pickup_drop', 'Pick-up and drop-off', 'পিক-আপ ও ড্রপ', 'Collect a permitted item from one place and deliver it to another.', 'box', 'fixed', 80.00, 0, 0.00, 0, 1, 0, 4),
('errand', 'Personal errand', 'ব্যক্তিগত কাজ', 'Stationery, books, a parcel or an item from a local shop.', 'bag', 'fixed', 80.00, 0, 0.00, 1, 1, 0, 5),
('custom', 'Custom task', 'অন্য যেকোনো কাজ', 'Describe any permitted task and suggest a fee. Helpers can make offers.', 'spark', 'offer', 80.00, 0, 0.00, 1, 1, 0, 6);
