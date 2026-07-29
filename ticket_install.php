<?php

if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_content (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        content_key VARCHAR(120) NOT NULL UNIQUE,
        content_type ENUM('text','button','status') NOT NULL DEFAULT 'text',
        value TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        emoji VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        emoji_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
        custom_emoji_id VARCHAR(64) NOT NULL DEFAULT '',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ticket_content_sort (content_type, enabled, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_options (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        option_group VARCHAR(64) NOT NULL,
        parent_key VARCHAR(100) NOT NULL DEFAULT '',
        option_key VARCHAR(100) NOT NULL,
        label VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        emoji VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        emoji_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
        custom_emoji_id VARCHAR(64) NOT NULL DEFAULT '',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ticket_option (option_group, parent_key, option_key),
        INDEX idx_ticket_options (option_group, parent_key, enabled, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_service_tokens (
        token VARCHAR(24) PRIMARY KEY,
        invoice_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_service_locks (
        invoice_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tracking VARCHAR(32) NOT NULL UNIQUE,
        idempotency_key VARCHAR(64) NOT NULL UNIQUE,
        user_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        invoice_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        service_username VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        panel_code VARCHAR(200) NOT NULL DEFAULT '',
        panel_name VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        panel_type VARCHAR(100) NOT NULL DEFAULT '',
        node_name VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
        user_snapshot_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        service_snapshot_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        os_key VARCHAR(100) NOT NULL,
        app_key VARCHAR(100) NOT NULL,
        app_version VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        internet_key VARCHAR(100) NOT NULL,
        provider_key VARCHAR(100) NOT NULL,
        province VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        city VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        problem_key VARCHAR(100) NOT NULL,
        alternate_test_key VARCHAR(100) NOT NULL,
        screenshot_file_id VARCHAR(500) NOT NULL DEFAULT '',
        description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        reopened_until DATETIME NULL,
        INDEX idx_ticket_owner (user_id, created_at),
        INDEX idx_ticket_service_status (invoice_id, status),
        INDEX idx_ticket_status_updated (status, updated_at),
        INDEX idx_ticket_outage (created_at, node_name(80), provider_key, province(80), app_key, problem_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (function_exists('addFieldToTable')) {
        addFieldToTable('tickets', 'user_snapshot_json', '{}', 'LONGTEXT');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id BIGINT UNSIGNED NOT NULL,
        sender_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        sender_role ENUM('user','admin','system') NOT NULL,
        message_type ENUM('text','photo','status') NOT NULL DEFAULT 'text',
        message_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        file_id VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ticket_messages (ticket_id, id),
        CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_drafts (
        user_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        current_step VARCHAR(64) NOT NULL,
        data_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_admin_sessions (
        admin_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        current_step VARCHAR(80) NOT NULL,
        data_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id BIGINT UNSIGNED NOT NULL,
        actor_id VARCHAR(200) NOT NULL,
        actor_role ENUM('user','admin','system') NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        old_status VARCHAR(32) NOT NULL DEFAULT '',
        new_status VARCHAR(32) NOT NULL DEFAULT '',
        event_data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ticket_events (ticket_id, id),
        CONSTRAINT fk_ticket_events_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_outage_alerts (
        signature_hash CHAR(64) PRIMARY KEY,
        signature_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        report_count INT NOT NULL,
        last_alert_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $settings = [
        'enabled' => '1',
        'cooldown_seconds' => '3600',
        'reopen_hours' => '24',
        'outage_window_minutes' => '30',
        'outage_threshold' => '5',
        'admin_chat_id' => '',
        'statistics_limit' => '10',
        'defaults_seeded' => '0',
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO ticket_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    $stmt = $pdo->prepare("SELECT setting_value FROM ticket_settings WHERE setting_key = 'defaults_seeded'");
    $stmt->execute();
    $shouldSeedDefaults = $stmt->fetchColumn() !== '1';

    $content = [
        ['main_report', 'button', 'گزارش اختلال', '🛠'],
        ['main_my_tickets', 'button', 'تیکت‌های من', '🎫'],
        ['service_report', 'button', 'گزارش مشکل این سرویس', '🛠'],
        ['guide_confirm', 'button', 'راهنما را بررسی کردم', '✅'],
        ['back', 'button', 'بازگشت', '🔙'],
        ['cancel', 'button', 'لغو فرایند', '❌'],
        ['confirm', 'button', 'تأیید و ثبت تیکت', '✅'],
        ['skip_photo', 'button', 'بدون تصویر ادامه بده', '⏭'],
        ['reply', 'button', 'ارسال پیام یا تصویر', '💬'],
        ['close', 'button', 'بستن تیکت', '🔒'],
        ['reopen', 'button', 'بازکردن مجدد تیکت', '🔓'],
        ['refresh', 'button', 'بروزرسانی', '♻️'],
        ['view_photo', 'button', 'مشاهده تصویر خطا', '🖼'],
        ['admin_menu', 'button', 'مدیریت تیکت‌ها', '🎫'],
        ['admin_open_tickets', 'button', 'تیکت‌های باز', '📥'],
        ['admin_all_tickets', 'button', 'همه تیکت‌ها', '📚'],
        ['admin_statistics', 'button', 'آمار اختلال', '📊'],
        ['admin_settings', 'button', 'تنظیمات سیستم', '⚙️'],
        ['admin_content', 'button', 'متن‌ها و دکمه‌ها', '✏️'],
        ['admin_options', 'button', 'گزینه‌های مراحل', '🧩'],
        ['admin_system_toggle', 'button', 'فعال/غیرفعال‌کردن کل سیستم', '🔌'],
        ['admin_add_content', 'button', 'ساخت متن یا دکمه جدید', '➕'],
        ['admin_reply', 'button', 'پاسخ به کاربر', '💬'],
        ['admin_request_info', 'button', 'درخواست اطلاعات بیشتر', '❓'],
        ['admin_change_status', 'button', 'تغییر وضعیت', '🔄'],
        ['admin_edit_value', 'button', 'ویرایش متن/عنوان', '✏️'],
        ['admin_edit_emoji', 'button', 'ویرایش ایموجی', '🙂'],
        ['admin_edit_custom_emoji', 'button', 'انتخاب از کتابخانه ایموجی', '💎'],
        ['admin_edit_group', 'button', 'ویرایش گروه', '🗂'],
        ['admin_edit_parent', 'button', 'ویرایش والد', '🧬'],
        ['admin_edit_key', 'button', 'ویرایش کلید', '🔑'],
        ['admin_toggle', 'button', 'فعال/غیرفعال', '🔌'],
        ['admin_move_up', 'button', 'انتقال به بالا', '⬆️'],
        ['admin_move_down', 'button', 'انتقال به پایین', '⬇️'],
        ['admin_delete', 'button', 'حذف', '🗑'],
        ['admin_add_option', 'button', 'ساخت گزینه جدید', '➕'],
        ['service_item', 'button', '{note}{service}', '🧾'],
        ['ticket_item', 'button', '{tracking} | {status}', '🎫'],
        ['admin_ticket_item', 'button', '{tracking} | {status} | {service}', '🎫'],
        ['admin_content_item', 'button', '{state} {key}', '📝'],
        ['admin_option_item', 'button', '{state} {label}{parent}', '🧩'],
        ['admin_cooldown', 'button', 'تنظیم Cooldown', '⏱'],
        ['admin_outage_window', 'button', 'بازه تشخیص اختلال', '🕒'],
        ['admin_outage_threshold', 'button', 'حد هشدار اختلال', '🚨'],
        ['admin_reopen_hours', 'button', 'مهلت بازکردن مجدد', '🔓'],
        ['admin_chat_id', 'button', 'شناسه چت اعلان ادمین', '📣'],
        ['guide', 'text', "پیش از ثبت گزارش، لطفاً این موارد را بررسی کنید:\n\n• لینک اشتراک را بروزرسانی کنید.\n• برنامه را کامل ببندید و دوباره باز کنید.\n• یک‌بار حالت پرواز را روشن و خاموش کنید.\n• با اینترنت دیگری تست کنید.\n• تاریخ و ساعت دستگاه روی حالت خودکار باشد.\n• در صورت وجود، مسیر یا کانفیگ جایگزین را امتحان کنید.\n\nپس از انجام موارد بالا دکمه زیر را بزنید.", '🧰'],
        ['select_service', 'text', 'سرویسی را که دچار مشکل شده انتخاب کنید.', '🧾'],
        ['no_services', 'text', 'سرویس قابل گزارشی برای حساب شما پیدا نشد.', '⛔️'],
        ['select_os', 'text', 'سیستم‌عامل دستگاه را انتخاب کنید.', '📱'],
        ['select_app', 'text', 'برنامه‌ای را که استفاده می‌کنید انتخاب کنید.', '📲'],
        ['ask_app_version', 'text', 'نسخه دقیق برنامه را ارسال کنید؛ مثال: 2.1.6', '🔢'],
        ['select_internet', 'text', 'نوع اینترنتی که با آن تست می‌کنید انتخاب کنید.', '🌐'],
        ['select_provider', 'text', 'اپراتور یا شرکت ارائه‌دهنده اینترنت را انتخاب کنید.', '📡'],
        ['ask_province', 'text', 'نام استان را ارسال کنید.', '🗺'],
        ['ask_city', 'text', 'نام شهر را ارسال کنید.', '🏙'],
        ['select_problem', 'text', 'نوع مشکل را انتخاب کنید.', '⚠️'],
        ['ask_alternate', 'text', 'آیا مسیرها یا کانفیگ‌های جایگزین را تست کرده‌اید؟', '🛣'],
        ['ask_photo', 'text', 'در صورت تمایل تصویر خطا را ارسال کنید یا بدون تصویر ادامه دهید.', '🖼'],
        ['photo_only', 'text', 'در این مرحله یک تصویر ارسال کنید یا دکمه «بدون تصویر» را بزنید.', 'ℹ️'],
        ['ask_description', 'text', 'مشکل را با جزئیات توضیح دهید؛ زمان شروع و نتیجه تست‌ها را نیز بنویسید.', '📝'],
        ['description_required', 'text', 'توضیحات نمی‌تواند خالی باشد. لطفاً شرح مشکل را ارسال کنید.', 'ℹ️'],
        ['summary', 'text', "<b>خلاصه گزارش اختلال</b>\n\nشناسه سرویس: <code>{service}</code>\nسیستم‌عامل: {os}\nبرنامه: {app}\nنسخه برنامه: {app_version}\nنوع اینترنت: {internet}\nاپراتور/شرکت: {provider}\nمنطقه: {province}، {city}\nنوع مشکل: {problem}\nتست مسیر جایگزین: {alternate}\nتصویر خطا: {photo}\n\nتوضیحات:\n{description}", '📋'],
        ['photo_attached', 'text', 'پیوست شده'],
        ['photo_not_attached', 'text', 'ارسال نشده'],
        ['role_admin', 'text', 'مدیریت'],
        ['role_user', 'text', 'کاربر'],
        ['role_system', 'text', 'سیستم'],
        ['message_photo', 'text', 'تصویر'],
        ['state_enabled', 'text', 'فعال'],
        ['state_disabled', 'text', 'غیرفعال'],
        ['unknown', 'text', 'نامشخص'],
        ['unlimited_unknown', 'text', 'نامحدود/نامشخص'],
        ['ticket_created', 'text', "تیکت با موفقیت ثبت شد.\nکد پیگیری: <code>{tracking}</code>\nوضعیت: {status}", '✅'],
        ['duplicate_open', 'text', 'برای این سرویس یک تیکت باز وجود دارد؛ به همان تیکت هدایت شدید.', 'ℹ️'],
        ['cooldown', 'text', 'برای ثبت گزارش جدید این سرویس باید {minutes} دقیقه دیگر صبر کنید.', '⏱'],
        ['draft_cancelled', 'text', 'فرایند ثبت گزارش لغو شد.', '✅'],
        ['my_tickets_title', 'text', 'فهرست تیکت‌های شما؛ برای مشاهده جزئیات یک مورد را انتخاب کنید.', '🎫'],
        ['my_tickets_empty', 'text', 'هنوز تیکتی ثبت نکرده‌اید.', '📭'],
        ['ticket_detail', 'text', "<b>تیکت {tracking}</b>\n\nسرویس: <code>{service}</code>\nمشکل: {problem}\nوضعیت: {status}\nثبت: {created_at}\nآخرین بروزرسانی: {updated_at}\n\nآخرین پیام‌ها:\n{messages}", '🎫'],
        ['reply_prompt', 'text', 'پیام یا تصویر جدید را ارسال کنید.', '💬'],
        ['reply_saved', 'text', 'پیام شما ثبت و برای مدیریت ارسال شد.', '✅'],
        ['ticket_closed', 'text', 'تیکت بسته شد و تا {hours} ساعت امکان بازکردن مجدد آن وجود دارد.', '🔒'],
        ['ticket_reopened', 'text', 'تیکت دوباره باز شد.', '🔓'],
        ['reopen_expired', 'text', 'مهلت بازکردن مجدد این تیکت تمام شده است.', '⛔️'],
        ['not_authorized', 'text', 'شما اجازه مشاهده یا تغییر این مورد را ندارید.', '⛔️'],
        ['invalid_action', 'text', 'این عملیات معتبر نیست یا اطلاعات آن منقضی شده است.', '⚠️'],
        ['new_ticket_admin', 'text', "<b>تیکت اختلال جدید</b>\n\nکد: <code>{tracking}</code>\nکاربر: <a href=\"tg://user?id={user_id}\">{user_id}</a>\nسرویس: <code>{service}</code>\nپنل: {panel}\nنود: {node}\nاپراتور: {provider}\nمنطقه: {province}، {city}\nبرنامه: {app}\nمشکل: {problem}", '🚨'],
        ['status_changed_user', 'text', "وضعیت تیکت <code>{tracking}</code> به «{status}» تغییر کرد.", '🔔'],
        ['new_reply_user', 'text', "پاسخ جدید برای تیکت <code>{tracking}</code> دریافت شد:\n\n{message}", '📨'],
        ['new_reply_admin', 'text', "کاربر در تیکت <code>{tracking}</code> پیام جدید ارسال کرد:\n\n{message}", '📨'],
        ['admin_dashboard', 'text', "مدیریت تیکت‌ها\n\nباز: {open}\nجدید: {new}\nدر حال بررسی: {in_progress}\nمنتظر کاربر: {waiting_user}", '🎫'],
        ['admin_ticket_list', 'text', 'فهرست تیکت‌ها؛ یک مورد را انتخاب کنید.', '📥'],
        ['admin_ticket_detail', 'text', "<b>مدیریت تیکت {tracking}</b>\n\nکاربر: <a href=\"tg://user?id={user_id}\">{user_id}</a>\nسرویس: <code>{service}</code>\nپنل: {panel}\nنود: {node}\nحجم باقی‌مانده: {remaining}\nانقضا: {expire}\nآخرین اتصال: {last_online}\nسیستم/برنامه: {os} / {app} {app_version}\nاینترنت: {internet} / {provider}\nمنطقه: {province}، {city}\nمشکل: {problem}\nمسیر جایگزین: {alternate}\nوضعیت: {status}\n\nتوضیحات:\n{description}\n\nآخرین پیام‌ها:\n{messages}", '🧾'],
        ['admin_reply_prompt', 'text', 'پاسخ متنی یا تصویر را ارسال کنید.', '💬'],
        ['admin_request_prompt', 'text', 'سؤالی را که برای تکمیل اطلاعات دارید ارسال کنید.', '❓'],
        ['admin_reply_saved', 'text', 'پاسخ ثبت و برای کاربر ارسال شد.', '✅'],
        ['admin_choose_status', 'text', 'وضعیت جدید تیکت را انتخاب کنید.', '🔄'],
        ['admin_statistics_title', 'text', "<b>آمار اختلال در {minutes} دقیقه اخیر</b>\n\nبر اساس نود:\n{nodes}\n\nبر اساس اپراتور:\n{providers}\n\nبر اساس منطقه:\n{regions}\n\nبر اساس برنامه:\n{apps}\n\nبر اساس نوع مشکل:\n{problems}", '📊'],
        ['outage_alert', 'text', "<b>هشدار اختلال گسترده</b>\n\nدر {minutes} دقیقه اخیر {count} گزارش مشابه ثبت شده است.\nنود: {node}\nاپراتور: {provider}\nمنطقه: {region}\nبرنامه: {app}\nمشکل: {problem}", '🚨'],
        ['admin_settings_title', 'text', "تنظیمات سیستم تیکت\n\nوضعیت کل سیستم: {enabled}\nCooldown: {cooldown} ثانیه\nمهلت بازکردن: {reopen} ساعت\nبازه اختلال: {window} دقیقه\nحد هشدار: {threshold} گزارش\nچت اعلان: {admin_chat_id}", '⚙️'],
        ['admin_send_chat_id', 'text', 'شناسه عددی کاربر/گروه ادمین را ارسال کنید؛ برای پاک‌کردن یک خط تیره (-) بفرستید.', '📣'],
        ['admin_send_number', 'text', 'مقدار جدید را فقط به‌صورت عدد ارسال کنید.', '🔢'],
        ['admin_saved', 'text', 'تغییر با موفقیت ذخیره شد.', '✅'],
        ['admin_content_title', 'text', 'متن یا دکمه موردنظر را برای مدیریت انتخاب کنید.', '✏️'],
        ['admin_content_detail', 'text', "<b>{key}</b>\nنوع: {type}\nوضعیت: {enabled}\nترتیب: {sort}\nایموجی: {emoji}\nکلید کتابخانه ایموجی: <code>{custom}</code>\n\nمقدار:\n{value}", '📝'],
        ['admin_options_title', 'text', 'گروه گزینه‌ها را انتخاب کنید.', '🧩'],
        ['admin_option_list', 'text', 'گزینه‌های گروه {group}؛ یک مورد را انتخاب کنید یا گزینه جدید بسازید.', '🧩'],
        ['admin_option_detail', 'text', "<b>{label}</b>\nکلید: <code>{key}</code>\nگروه: {group}\nوالد: {parent}\nوضعیت: {enabled}\nترتیب: {sort}\nایموجی: {emoji}\nکلید کتابخانه ایموجی: <code>{custom}</code>", '🧩'],
        ['admin_send_value', 'text', 'مقدار جدید را ارسال کنید. برای پاک‌کردن مقدار، یک خط تیره (-) بفرستید.', '✏️'],
        ['admin_delete_confirm', 'text', 'برای حذف قطعی دوباره دکمه حذف را بزنید.', '⚠️'],
        ['admin_option_group_prompt', 'text', 'نام فنی گروه را ارسال کنید؛ مانند os، app، internet، provider، problem یا alternate_test.', '1️⃣'],
        ['admin_option_parent_prompt', 'text', 'کلید والد را ارسال کنید؛ اگر والد ندارد یک خط تیره (-) بفرستید.', '2️⃣'],
        ['admin_option_key_prompt', 'text', 'یک کلید فنی یکتا با حروف انگلیسی، عدد یا خط زیر ارسال کنید.', '3️⃣'],
        ['admin_option_label_prompt', 'text', 'عنوان نمایشی گزینه را ارسال کنید.', '4️⃣'],
        ['admin_option_created', 'text', 'گزینه جدید ساخته شد.', '✅'],
        ['admin_content_key_prompt', 'text', 'کلید فنی یکتای متن را با حروف انگلیسی، عدد یا خط زیر ارسال کنید.', '1️⃣'],
        ['admin_content_type_prompt', 'text', 'نوع را ارسال کنید: text یا button یا status', '2️⃣'],
        ['admin_content_value_prompt', 'text', 'متن یا عنوان اولیه را ارسال کنید.', '3️⃣'],
        ['admin_content_created', 'text', 'متن یا دکمه جدید ساخته شد.', '✅'],
        ['status_changed_admin', 'text', "وضعیت تیکت <code>{tracking}</code> توسط کاربر به «{status}» تغییر کرد.", '🔔'],
        ['status_new', 'status', 'جدید', '🆕'],
        ['status_in_progress', 'status', 'در حال بررسی', '🔎'],
        ['status_waiting_user', 'status', 'منتظر پاسخ کاربر', '⏳'],
        ['status_resolved', 'status', 'حل‌شده', '✅'],
        ['status_closed', 'status', 'بسته‌شده', '🔒'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO ticket_content (content_key, content_type, value, emoji, sort_order) VALUES (?, ?, ?, ?, ?)");
    if ($shouldSeedDefaults) {
        foreach ($content as $index => $row) {
            $stmt->execute([$row[0], $row[1], $row[2], $row[3], $index + 1]);
        }
    }

    $options = [
        ['os', '', 'android', 'Android', '🤖'],
        ['os', '', 'ios', 'iOS', '🍎'],
        ['os', '', 'windows', 'Windows', '🪟'],
        ['os', '', 'macos', 'macOS', '💻'],
        ['os', '', 'other', 'سایر', '📱'],
        ['app', 'android', 'v2rayng', 'v2rayNG', '📲'],
        ['app', 'android', 'hiddify', 'Hiddify', '🟦'],
        ['app', 'android', 'nekobox', 'NekoBox', '🐱'],
        ['app', 'android', 'singbox', 'Sing-box', '📦'],
        ['app', 'ios', 'streisand', 'Streisand', '🛡'],
        ['app', 'ios', 'v2box', 'V2Box', '📦'],
        ['app', 'ios', 'shadowrocket', 'Shadowrocket', '🚀'],
        ['app', 'ios', 'hiddify', 'Hiddify', '🟦'],
        ['app', 'windows', 'hiddify', 'Hiddify', '🟦'],
        ['app', 'windows', 'v2rayn', 'v2rayN', '📲'],
        ['app', 'windows', 'nekoray', 'NekoRay', '🐱'],
        ['app', 'windows', 'singbox', 'Sing-box', '📦'],
        ['app', 'macos', 'streisand', 'Streisand', '🛡'],
        ['app', 'macos', 'v2box', 'V2Box', '📦'],
        ['app', 'macos', 'hiddify', 'Hiddify', '🟦'],
        ['app', 'macos', 'singbox', 'Sing-box', '📦'],
        ['app', 'other', 'other', 'سایر برنامه‌ها', '📲'],
        ['internet', '', 'mobile', 'دیتای موبایل', '📶'],
        ['internet', '', 'adsl', 'ADSL / VDSL', '☎️'],
        ['internet', '', 'fiber', 'فیبر نوری', '💡'],
        ['internet', '', 'tdlte', 'TD-LTE', '📡'],
        ['internet', '', 'wifi', 'Wi-Fi عمومی/سازمانی', '🛜'],
        ['internet', '', 'other', 'سایر', '🌐'],
        ['provider', 'mobile', 'mci', 'همراه اول', '📱'],
        ['provider', 'mobile', 'irancell', 'ایرانسل', '📱'],
        ['provider', 'mobile', 'rightel', 'رایتل', '📱'],
        ['provider', 'mobile', 'shatel_mobile', 'شاتل موبایل', '📱'],
        ['provider', 'adsl', 'mci', 'مخابرات', '☎️'],
        ['provider', 'adsl', 'shatel', 'شاتل', '🌐'],
        ['provider', 'adsl', 'asiatech', 'آسیاتک', '🌐'],
        ['provider', 'adsl', 'parsonline', 'پارس آنلاین', '🌐'],
        ['provider', 'adsl', 'hiweb', 'های‌وب', '🌐'],
        ['provider', 'fiber', 'mci', 'مخابرات', '💡'],
        ['provider', 'fiber', 'irancell', 'ایرانسل', '💡'],
        ['provider', 'fiber', 'shatel', 'شاتل', '💡'],
        ['provider', 'tdlte', 'irancell', 'ایرانسل', '📡'],
        ['provider', 'tdlte', 'mobicom', 'مبین‌نت', '📡'],
        ['provider', 'tdlte', 'hiweb', 'های‌وب', '📡'],
        ['provider', 'wifi', 'organization', 'سازمانی/اداری', '🏢'],
        ['provider', 'wifi', 'public', 'عمومی', '🛜'],
        ['provider', 'other', 'other', 'سایر شرکت‌ها', '🌐'],
        ['problem', '', 'no_connection', 'عدم اتصال', '⛔️'],
        ['problem', '', 'slow_speed', 'سرعت پایین', '🐢'],
        ['problem', '', 'frequent_disconnect', 'قطع و وصل مکرر', '🔁'],
        ['problem', '', 'subscription_update', 'بروزرسانی‌نشدن لینک اشتراک', '🔄'],
        ['problem', '', 'specific_app', 'بازنشدن برنامه یا سایت خاص', '📵'],
        ['problem', '', 'high_ping', 'پینگ بالا', '📈'],
        ['problem', '', 'other', 'سایر مشکلات', '⚠️'],
        ['alternate_test', '', 'yes', 'بله، تست کردم', '✅'],
        ['alternate_test', '', 'no', 'خیر، تست نکردم', '❌'],
        ['alternate_test', '', 'not_available', 'مسیر جایگزین ندارم', '➖'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO ticket_options (option_group, parent_key, option_key, label, emoji, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    $sorts = [];
    if ($shouldSeedDefaults) {
        foreach ($options as $row) {
            $sortKey = $row[0] . '|' . $row[1];
            $sorts[$sortKey] = ($sorts[$sortKey] ?? 0) + 1;
            $stmt->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $sorts[$sortKey]]);
        }
        $pdo->prepare("UPDATE ticket_settings SET setting_value = '1' WHERE setting_key = 'defaults_seeded'")->execute();
    }
} catch (Throwable $e) {
    error_log('[Mirza Ticket Install] ' . $e->getMessage());
}
