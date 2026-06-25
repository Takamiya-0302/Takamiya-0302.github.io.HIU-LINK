<?php
// ==========================================
// ⚙️ バックエンド処理（PHP + SQLite + セッション）
// ==========================================
session_start();

$db_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'HIU-LINK.db';
$pdo = new PDO('sqlite:' . $db_path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA foreign_keys = ON;");

// 1. 本番用テーブル作成（パスワードハッシュ対応）
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        password TEXT NOT NULL,
        createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        category TEXT,
        recruitmentType TEXT,
        title TEXT NOT NULL,
        location TEXT,
        eventDate TEXT,
        eventEndDate TEXT,
        deadline TEXT,
        summary TEXT,
        description TEXT,
        max_participants INTEGER DEFAULT 0,
        createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        text TEXT NOT NULL,
        createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        UNIQUE(post_id, user_id)
    );
");

// 2. 本番＆ローカル両対応のメール送信関数
function send_app_mail($to, $subject, $body) {
    $is_local = (php_sapi_name() === 'cli-server' || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['SERVER_NAME'] ?? '', '127.0.0.1') !== false);
    
    if ($is_local) {
        $log = "========== MAIL LOG ==========\n";
        $log .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $log .= "To: {$to}\n";
        $log .= "Subject: {$subject}\n";
        $log .= "Body:\n{$body}\n";
        $log .= "==============================\n\n";
        $log_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mail_log.txt';
        file_put_contents($log_path, $log, FILE_APPEND);
        return true;
    } else {
        mb_language("Japanese"); mb_internal_encoding("UTF-8");
        // ★ご友人指定の送信元ドメインヘッダーを適用
        $headers = "From: noreply@genbu2.rmme.do-johodai.ac.jp";
        return @mb_send_mail($to, $subject, $body, $headers);
    }
}

$action = $_GET['action'] ?? '';
$my_user_id = $_SESSION['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 【新規登録1】アカウント情報の一時保持 ＆ 認証コード送信
    if ($action === 'send_register_otp') {
        $email = $_POST['email'] ?? '';
        $name = $_POST['name'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (!$email || !$name || !$password) {
            http_response_code(400); echo "すべての項目を入力してください。"; exit;
        }
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            http_response_code(400); echo "このメールアドレスは既に登録されています。"; exit;
        }
        
        $otp = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $_SESSION['reg_otp'] = $otp;
        $_SESSION['reg_email'] = $email;
        $_SESSION['reg_name'] = $name;
        $_SESSION['reg_pass'] = $password;
        
        $subject = "【HIU-LINK】新規登録認証コード";
        $body = "認証コード: 【 " . $otp . " 】\n\n画面に入力して登録を完了してください。";
        
        send_app_mail($email, $subject, $body);
        echo "OK"; exit;
    }
    
    // 【新規登録2】コード照合 ➔ データベースへ書き込み
    if ($action === 'confirm_register') {
        $code = $_POST['code'] ?? '';
        if (!$code || $code !== ($_SESSION['reg_otp'] ?? '')) {
            http_response_code(401); echo "認証コードが間違っています。"; exit;
        }
        
        $email = $_SESSION['reg_email'];
        $name = $_SESSION['reg_name'];
        $raw_pass = $_SESSION['reg_pass'];
        
        // パスワードを安全にハッシュ化して保存
        $hashed_pass = password_hash($raw_pass, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (email, name, password) VALUES (?, ?, ?)");
        $stmt->execute([$email, $name, $hashed_pass]);
        
        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['user_name'] = $name;
        $_SESSION['email'] = $email;
        
        unset($_SESSION['reg_otp'], $_SESSION['reg_email'], $_SESSION['reg_name'], $_SESSION['reg_pass']);
        echo "OK"; exit;
    }

    // 【ログイン】メールアドレスとパスワードの照合
    if ($action === 'login') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            echo "OK";
        } else {
            http_response_code(401); echo "メールアドレスまたはパスワードが間違っています。";
        }
        exit;
    }

    // 【投稿作成】
    if ($action === 'create_post') {
        if (!$my_user_id) { http_response_code(401); echo "ログインが必要です"; exit; }
        $stmt = $pdo->prepare("INSERT INTO posts (user_id, category, recruitmentType, title, location, eventDate, eventEndDate, deadline, summary, description, max_participants, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $my_user_id, $_POST['category'] ?? '', $_POST['recruitmentType'] ?? '',
            $_POST['title'] ?? '', $_POST['location'] ?? '', $_POST['eventDate'] ?? '',
            $_POST['eventEndDate'] ?? '', $_POST['deadline'] ?? '', $_POST['summary'] ?? '',
            $_POST['description'] ?? '', (int)($_POST['max_participants'] ?? 0), date('c')
        ]);
        echo "OK"; exit;
    }

    // 【投稿編集】
    if ($action === 'edit_post') {
        if (!$my_user_id) { http_response_code(401); echo "ログインが必要です"; exit; }
        $stmt = $pdo->prepare("UPDATE posts SET category=?, recruitmentType=?, title=?, location=?, eventDate=?, eventEndDate=?, deadline=?, summary=?, description=?, max_participants=? WHERE id=? AND user_id=?");
        $stmt->execute([
            $_POST['category'] ?? '', $_POST['recruitmentType'] ?? '', $_POST['title'] ?? '',
            $_POST['location'] ?? '', $_POST['eventDate'] ?? '', $_POST['eventEndDate'] ?? '',
            $_POST['deadline'] ?? '', $_POST['summary'] ?? '', $_POST['description'] ?? '',
            (int)($_POST['max_participants'] ?? 0), $_POST['post_id'] ?? 0, $my_user_id
        ]);
        echo "OK"; exit;
    }

    // 【投稿削除】
    if ($action === 'delete_post') {
        if (!$my_user_id) { http_response_code(401); echo "ログインが必要です"; exit; }
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id=? AND user_id=?");
        $stmt->execute([$_POST['post_id'] ?? 0, $my_user_id]);
        echo "OK"; exit;
    }
    
    // 【コメント作成 ＆ 本番メール通知】
    if ($action === 'create_comment') {
        if (!$my_user_id) { http_response_code(401); echo "ログインが必要です"; exit; }
        $postId = $_POST['postId'] ?? 0;
        $text = $_POST['text'] ?? '';
        $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, text, createdAt) VALUES (?, ?, ?, ?)");
        $stmt->execute([$postId, $my_user_id, $text, date('c')]);
        
        $stmt = $pdo->prepare("SELECT p.title, u.email, u.id as owner_id FROM posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $stmt->execute([$postId]);
        $pinfo = $stmt->fetch();
        
        if ($pinfo && $pinfo['owner_id'] != $my_user_id) {
            $sender = $_SESSION['user_name'];
            $subject = "【HIU-LINK】あなたの投稿にコメントがつきました";
            $body = "{$sender} さんが、あなたの投稿「{$pinfo['title']}」にコメントしました。\n\n内容:\n{$text}\n\nHIU-LINKで確認してください。";
            send_app_mail($pinfo['email'], $subject, $body);
        }
        echo "OK"; exit;
    }

    // 【参加登録 ＆ 本番メール通知】
    if ($action === 'toggle_join') {
        if (!$my_user_id) { http_response_code(401); echo "ログインが必要です"; exit; }
        $post_id = $_POST['post_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT p.max_participants, p.title, u.id as owner_id, u.email, 
            (SELECT COUNT(*) FROM participants WHERE post_id = p.id) as current_count 
            FROM posts p JOIN users u ON p.user_id = u.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$post_id]);
        $post_info = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT * FROM participants WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$post_id, $my_user_id]);
        $is_joined = $stmt->fetch();
        
        if ($is_joined) {
            $stmt = $pdo->prepare("DELETE FROM participants WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$post_id, $my_user_id]);
            echo "LEFT";
        } else {
            if ($post_info['max_participants'] > 0 && $post_info['current_count'] >= $post_info['max_participants']) {
                http_response_code(400); echo "上限人数に達しています"; exit;
            }
            $stmt = $pdo->prepare("INSERT INTO participants (post_id, user_id) VALUES (?, ?)");
            $stmt->execute([$post_id, $my_user_id]);
            
            if ($post_info && $post_info['owner_id'] != $my_user_id) {
                $sender = $_SESSION['user_name'];
                $subject = "【HIU-LINK】あなたの募集に参加者が増えました！";
                $body = "{$sender} さんが、あなたの投稿「{$post_info['title']}」に参加しました。\n\nHIU-LINKで確認してください。";
                send_app_mail($post_info['email'], $subject, $body);
            }
            echo "JOINED";
        }
        exit;
    }
}
elseif ($action === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// データ読み込み
$stmt = $pdo->prepare("
    SELECT p.*, u.name AS author,
    (SELECT COUNT(*) FROM participants WHERE post_id = p.id) AS current_participants,
    EXISTS(SELECT 1 FROM participants WHERE post_id = p.id AND user_id = :uid) AS is_joined_by_me
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    ORDER BY p.createdAt DESC
");
$stmt->execute([':uid' => $my_user_id ?: 0]);
$all_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$all_comments = $pdo->query("
    SELECT c.*, u.name 
    FROM comments c 
    JOIN users u ON c.user_id = u.id 
    ORDER BY c.createdAt ASC
")->fetchAll(PDO::FETCH_ASSOC);

$comments_by_post = [];
foreach ($all_comments as $c) {
    $comments_by_post[$c['post_id']][] = [ 'name' => $c['name'], 'text' => $c['text'], 'createdAt' => $c['createdAt'] ];
}

foreach ($all_posts as &$p) {
    $p['comments'] = $comments_by_post[$p['id']] ?? [];
    $p['mapPoint'] = ['label' => $p['location'], 'latitude' => null, 'longitude' => null, 'updatedAt' => $p['createdAt']];
}

$json_posts = json_encode($all_posts, JSON_UNESCAPED_UNICODE);
$current_user_name = isset($_SESSION['user_name']) ? json_encode($_SESSION['user_name'], JSON_UNESCAPED_UNICODE) : 'null';
$current_user_id = isset($_SESSION['user_id']) ? json_encode($_SESSION['user_id']) : 'null';
$current_user_email = isset($_SESSION['email']) ? json_encode($_SESSION['email']) : 'null';
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>HIU Link</title>
  <style>
    :root {
      --bg: #f4f7f1; --bg-accent: #eef4fb; --card: #ffffff; --card-muted: #f7f9fc;
      --text: #17324d; --muted: #5a6c7d; --border: #d8e0e8; --accent: #0f766e;
      --accent-strong: #115e59; --accent-soft: #d8f3ef; --warning: #c27b1d;
      --shadow: 0 18px 40px rgba(32, 66, 91, 0.08); --radius: 20px;
      --content-max-width: 1120px; --content-inline-padding: 20px;
      --menu-toggle-top: 20px; --menu-toggle-left: 20px; --menu-toggle-size: 46px; --menu-toggle-gap: 20px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; font-family: "Segoe UI", "Yu Gothic UI", "Hiragino Sans", sans-serif; color: var(--text);
      background: radial-gradient(circle at top left, rgba(15, 118, 110, 0.09), transparent 28%), linear-gradient(180deg, var(--bg) 0%, var(--bg-accent) 100%);
    }
    body.menu-open { overflow: hidden; }
    button, input, select, textarea { font: inherit; }
    button { cursor: pointer; }
    .menu-overlay { position: fixed; inset: 0; z-index: 40; background: rgba(23, 50, 77, 0.34); opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
    .menu-overlay.is-visible { opacity: 1; pointer-events: auto; }
    
    .auth-modal { display: flex; align-items: center; justify-content: center; z-index: 100; }
    .auth-card { width: 90%; max-width: 400px; padding: 30px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); position: relative; }
    .auth-btn-row { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; gap: 10px; }
    .text-center { text-align: center; }

    .side-menu { position: fixed; top: 0; left: 0; z-index: 50; width: min(320px, 86vw); height: 100vh; padding: 24px 18px; background: rgba(255, 255, 255, 0.98); border-right: 1px solid rgba(216, 224, 232, 0.9); box-shadow: 18px 0 36px rgba(23, 50, 77, 0.14); transform: translateX(-100%); transition: transform 0.22s ease; }
    .side-menu.is-open { transform: translateX(0); }
    .side-menu-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
    .side-menu-header h2 { margin: 0; font-size: 1.1rem; }
    .menu-close-button, .menu-toggle-button { display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 16px; background: rgba(255, 255, 255, 0.92); box-shadow: inset 0 0 0 1px rgba(23, 50, 77, 0.08); color: var(--text); }
    .menu-close-button { width: 42px; height: 42px; font-size: 1.5rem; }
    .side-menu-nav { display: grid; gap: 10px; }
    .side-menu-link { width: 100%; padding: 14px 16px; border: none; border-radius: 16px; text-align: left; background: var(--card-muted); color: var(--text); box-shadow: inset 0 0 0 1px var(--border); transition: transform 0.16s ease, background-color 0.16s ease, box-shadow 0.16s ease; }
    .side-menu-link.is-active, .side-menu-link:hover { background: var(--accent-soft); color: var(--accent-strong); box-shadow: inset 0 0 0 1px rgba(15, 118, 110, 0.18); transform: translateY(-1px); }
    .app-header { padding: var(--menu-toggle-top) 0 18px; }
    .topbar { display: flex; align-items: center; justify-content: space-between; max-width: var(--content-max-width); margin: 0 auto; min-height: var(--menu-toggle-size); padding: 0 var(--content-inline-padding); }
    .topbar-left { display: flex; align-items: center; gap: var(--menu-toggle-gap); }
    .page-shell { max-width: var(--content-max-width); margin: 0 auto; padding: 0 var(--content-inline-padding) 48px; }
    .menu-toggle-button { position: relative; width: var(--menu-toggle-size); height: var(--menu-toggle-size); padding: 0; flex-shrink: 0; flex-direction: column; gap: 4px; border-radius: 14px; background: rgba(54, 61, 69, 0.96); box-shadow: 0 12px 24px rgba(23, 50, 77, 0.2), inset 0 0 0 1px rgba(255, 255, 255, 0.08); }
    .menu-toggle-button span { display: block; width: 18px; height: 2px; margin: 0; border-radius: 999px; background: #ffffff; }
    .menu-toggle-button:hover { background: rgba(43, 49, 56, 0.98); box-shadow: 0 14px 26px rgba(23, 50, 77, 0.24), inset 0 0 0 1px rgba(255, 255, 255, 0.08); }
    body.menu-open .menu-toggle-button { background: rgba(43, 49, 56, 0.98); box-shadow: 0 14px 26px rgba(23, 50, 77, 0.24), inset 0 0 0 1px rgba(255, 255, 255, 0.08); }
    .app-title { margin: 0; font-size: clamp(1.6rem, 3vw, 2.2rem); line-height: 1.05; letter-spacing: 0.01em; }
    .chip-button, .secondary-button, .primary-button, .link-button { border: none; border-radius: 999px; transition: transform 0.16s ease, box-shadow 0.16s ease, background-color 0.16s ease; }
    .section-card, .post-card, .detail-card, .comments-card, .form-card, .empty-card { background: rgba(255, 255, 255, 0.92); border: 1px solid rgba(255, 255, 255, 0.65); border-radius: var(--radius); box-shadow: var(--shadow); }
    .view-root { min-height: 480px; }
    .section-card, .detail-card, .comments-card, .form-card, .empty-card { padding: 24px; }
    .section-header, .detail-header, .form-header, .comments-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
    .create-sticky-bar { position: sticky; top: 12px; z-index: 20; display: flex; justify-content: flex-start; margin: -4px 0 18px; padding: 8px 0; background: linear-gradient(180deg, rgba(244, 247, 241, 0.96) 0%, rgba(244, 247, 241, 0.7) 72%, rgba(244, 247, 241, 0) 100%); backdrop-filter: blur(4px); }
    .section-title, .detail-title, .form-title, .comments-title { margin: 0; font-size: 1.45rem; }
    .section-subtitle, .detail-subtitle, .form-subtitle, .comments-subtitle { margin: 8px 0 0; color: var(--muted); line-height: 1.6; }
    .stats-row, .list-tools, .meta-grid, .comments-list, .field-grid, .post-grid { margin-top: 20px; }
    .stats-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
    .stat-box { padding: 16px; border-radius: 16px; background: var(--card-muted); border: 1px solid var(--border); }
    .stat-box span { display: block; margin-bottom: 6px; font-size: 0.9rem; color: var(--muted); }
    .stat-box strong { font-size: 1.1rem; }
    .list-toolbar { display: grid; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid rgba(216, 224, 232, 0.95); }
    .list-toolbar-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(220px, 1fr) minmax(160px, auto); gap: 12px; align-items: end; }
    .tool-field { display: grid; gap: 6px; padding: 0; border: none; background: transparent; }
    .tool-field label { font-size: 0.92rem; font-weight: 600; color: var(--muted); }
    .toolbar-label { font-size: 0.92rem; font-weight: 600; color: var(--muted); }
    .tool-field-action { align-content: end; }
    .toolbar-create-button { width: 100%; min-height: 48px; }
    .toolbar-filter-group { display: grid; gap: 8px; padding-top: 4px; }
    .toolbar-chip-row { display: flex; flex-wrap: wrap; gap: 8px; }
    .list-helper { display: flex; justify-content: flex-end; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 4px; }
    .list-action-buttons, .badge-row { display: flex; gap: 10px; flex-wrap: wrap; }
    .badge-row { margin-bottom: 10px; }
    .chip-button { padding: 10px 16px; background: #fff; color: var(--text); box-shadow: inset 0 0 0 1px var(--border); }
    .chip-button.is-active, .chip-button:hover { background: var(--accent-soft); color: var(--accent-strong); box-shadow: inset 0 0 0 1px rgba(15, 118, 110, 0.18); }
    .post-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
    .post-card { padding: 20px; transition: 0.2s; }
    .badge { display: inline-flex; align-items: center; padding: 7px 12px; border-radius: 999px; font-size: 0.86rem; font-weight: 700; color: var(--accent-strong); background: var(--accent-soft); }
    .badge-secondary { color: #20466b; background: #e3edf9; }
    .status-badge { display: inline-flex; align-items: center; padding: 7px 12px; border-radius: 999px; font-size: 0.86rem; font-weight: 700; }
    .status-badge-before { color: #7a4d14; background: #fff0cf; }
    .status-badge-active { color: #0f6b42; background: #d8f7e7; }
    .status-badge-ended { color: #6b7280; background: #eceff3; }
    .status-badge-open { color: #7b3f98; background: #f2e7fb; }
    .post-title { margin: 14px 0 10px; font-size: 1.15rem; line-height: 1.4; }
    .post-summary { margin: 0 0 16px; color: var(--muted); line-height: 1.7; }
    .meta-list, .detail-meta-list { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
    .meta-item, .detail-meta-item { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--border); }
    .meta-item:first-child, .detail-meta-item:first-child { border-top: none; padding-top: 0; }
    .meta-label { color: var(--muted); }
    .link-button, .secondary-button, .primary-button { padding: 12px 18px; }
    .link-button { background: transparent; color: var(--accent-strong); padding-left: 0; }
    .secondary-button { background: #fff; color: var(--text); box-shadow: inset 0 0 0 1px var(--border); }
    .primary-button { background: var(--accent); color: #fff; box-shadow: 0 14px 24px rgba(15, 118, 110, 0.18); }
    .scroll-top-button { position: fixed; right: 24px; bottom: 24px; z-index: 24; width: 52px; height: 52px; border: none; border-radius: 999px; background: rgba(15, 118, 110, 0.96); color: #fff; font-size: 1.35rem; font-weight: 700; box-shadow: 0 16px 28px rgba(15, 118, 110, 0.22); opacity: 0; pointer-events: none; transform: translateY(12px); transition: opacity 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease; }
    .scroll-top-button.is-visible { opacity: 1; pointer-events: auto; transform: translateY(0); }
    .scroll-top-button:hover { box-shadow: 0 18px 32px rgba(15, 118, 110, 0.28); transform: translateY(-1px); }
    .sticky-back-button { box-shadow: 0 10px 24px rgba(23, 50, 77, 0.08), inset 0 0 0 1px var(--border); }
    .secondary-button:hover, .primary-button:hover, .link-button:hover { transform: translateY(-1px); }
    .detail-layout { display: grid; gap: 18px; }
    .detail-content { margin-top: 18px; line-height: 1.8; color: var(--text); }
    .detail-content p { margin: 0; }
    .meta-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .detail-meta-card { padding: 18px; border-radius: 16px; border: 1px solid var(--border); background: var(--card-muted); }
    .detail-meta-card h3 { margin: 0 0 12px; font-size: 1rem; }
    .comment-list { display: grid; gap: 12px; margin-top: 18px; }
    .comment-card { padding: 16px; border-radius: 16px; background: var(--card-muted); border: 1px solid var(--border); }
    .comment-card-header { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
    .comment-author { font-weight: 700; }
    .comment-time { font-size: 0.9rem; color: var(--muted); }
    .comment-body { margin: 0; line-height: 1.7; color: var(--text); }
    .field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .field { display: grid; gap: 8px; }
    .field.is-full { grid-column: 1 / -1; }
    .field label { font-weight: 700; }
    .tool-field input, .tool-field select, .field input, .field select, .field textarea { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 14px; background: #fff; color: var(--text); }
    .field textarea { min-height: 120px; resize: vertical; }
    .helper-text { margin: 0; color: var(--muted); line-height: 1.6; }
    .form-actions, .detail-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 22px; }
    .notice { margin-top: 18px; padding: 14px 16px; border-radius: 16px; background: #fff7e8; color: #7d4e08; border: 1px solid rgba(194, 123, 29, 0.2); }
    .empty-card { text-align: center; }
    .empty-card p { margin: 0 0 16px; color: var(--muted); line-height: 1.7; }
    
    /* ★新規登録モーダル用に追加されたスタイル */
    .register-form-grid { display: grid; gap: 12px; margin-bottom: 15px; }
  </style>
</head>
<body>

  <div id="auth-modal-overlay" class="menu-overlay auth-modal" aria-hidden="true">
    <div class="form-card auth-card">
      <div id="login-choice-step">
        <h2 class="form-title text-center" style="margin-bottom:5px;">HIU Link へようこそ</h2>
        <p class="form-subtitle text-center" style="margin-bottom:20px;">ログインまたはアカウント登録を行ってください。</p>
        <div style="display:grid; gap:12px;">
          <button type="button" class="primary-button" onclick="showLoginStep1()">ログイン</button>
          <button type="button" class="secondary-button" onclick="showRegisterStep1()">新規アカウント登録</button>
          <button type="button" class="link-button text-center" id="btn-skip-login" style="color:var(--muted); text-decoration:underline; width:100%;">スキップして見るだけ</button>
        </div>
      </div>

      <div id="login-step-1" style="display: none;">
        <h2 class="form-title text-center" style="margin-bottom:15px;">ログイン</h2>
        <div class="field is-full"><label>メールアドレス</label><input type="email" id="loginEmail"></div>
        <div class="field is-full" style="margin-top:10px;"><label>パスワード</label><input type="password" id="loginPassword"></div>
        <div class="auth-btn-row">
          <button type="button" class="secondary-button" onclick="backToChoice()">戻る</button>
          <button type="button" class="primary-button" onclick="executeLogin()">ログイン</button>
        </div>
      </div>

      <div id="register-step-1" style="display: none;">
        <h2 class="form-title text-center" style="margin-bottom:15px;">新規アカウント登録</h2>
        <div class="register-form-grid">
          <div class="field is-full"><label>ユーザーネーム（表示名）</label><input type="text" id="regName" placeholder="例: 田中太郎"></div>
          <div class="field is-full"><label>学校のメールアドレス</label><input type="email" id="regEmail" placeholder="例: test@example.com"></div>
          <div class="field is-full"><label>パスワード</label><input type="password" id="regPassword" placeholder="半角英数字6文字以上"></div>
        </div>
        <div class="auth-btn-row">
          <button type="button" class="secondary-button" onclick="backToChoice()">戻る</button>
          <button type="button" class="primary-button" onclick="sendRegisterOtp()">コードを送信</button>
        </div>
      </div>

      <div id="register-step-2" style="display: none;">
        <h2 class="form-title text-center" style="margin-bottom:5px;">メール認証</h2>
        <p class="form-subtitle text-center" style="margin-bottom:20px;">ご入力いただいたメールアドレス宛に届いた4桁の数字を入力してください。</p>
        <div class="field is-full">
          <input type="text" id="regCode" placeholder="0000" maxlength="4" style="text-align: center; font-size: 1.5rem; letter-spacing: 8px;">
        </div>
        <div class="auth-btn-row" style="justify-content: flex-end;">
          <button type="button" class="primary-button" onclick="confirmRegister()">登録を完了する</button>
        </div>
      </div>

    </div>
  </div>

  <div id="menu-overlay" class="menu-overlay" data-action="close-menu" aria-hidden="true"></div>
  <aside id="side-menu" class="side-menu" aria-hidden="true">
    <div class="side-menu-header">
      <h2>メニュー</h2>
      <button type="button" class="menu-close-button" data-action="close-menu">&times;</button>
    </div>
    <nav class="side-menu-nav">
      <button type="button" class="side-menu-link is-active" data-route="list">投稿一覧</button>
      <button type="button" class="side-menu-link" data-route="create">投稿作成</button>
      <button type="button" class="side-menu-link" id="nav-login-btn" style="display: none;">ログイン / 登録</button>
      <a href="admin.php" class="side-menu-link" id="nav-admin-btn" style="text-decoration:none; display:none; background-color:#ffc107; color:#333; font-weight:bold;">⚙️ 管理画面</a>
      <a href="?action=logout" class="side-menu-link" id="nav-logout-btn" style="text-decoration:none; display:none;">ログアウト</a>
    </nav>
  </aside>

  <header class="app-header">
    <div class="topbar">
      <div class="topbar-left">
        <button type="button" id="menu-toggle" class="menu-toggle-button" data-action="toggle-menu">
          <span></span><span></span><span></span>
        </button>
        <h1 class="app-title">HIU Link</h1>
      </div>
      <div id="user-greeting" style="font-weight: bold; color: var(--accent-strong);"></div>
    </div>
  </header>

  <div class="page-shell">
    <main id="view-root" class="view-root" aria-live="polite"></main>
  </div>

  <script>
    window.CAMPUS_BOARD_DB_POSTS = <?= $json_posts ?>;
    window.CAMPUS_BOARD_CATEGORIES = ["参加者募集", "協力者募集", "イベント告知"];
    window.CAMPUS_BOARD_RECRUITMENT_TYPES = ["リアルタイム募集", "イベント予約募集"];
    
    window.CURRENT_USER = <?= $current_user_name ?>;
    window.CURRENT_USER_ID = <?= $current_user_id ?>;
    window.CURRENT_USER_EMAIL = <?= $current_user_email ?>;

    const authOverlay = document.getElementById('auth-modal-overlay');
    
    if (!window.CURRENT_USER) {
        authOverlay.classList.add('is-visible');
        document.getElementById('nav-login-btn').style.display = 'block';
    } else {
        document.getElementById('user-greeting').textContent = '👤 ' + window.CURRENT_USER;
        document.getElementById('nav-logout-btn').style.display = 'block';
        
        if (window.CURRENT_USER_EMAIL === 'xiudougaogong@gmail.com') {
            document.getElementById('nav-admin-btn').style.display = 'block';
        }
    }

    // スキップボタン
    document.getElementById('btn-skip-login').addEventListener('click', function() {
        authOverlay.classList.remove('is-visible');
    });
    
    // モーダルの遷移制御
    function showLoginStep1() {
        document.getElementById('login-choice-step').style.display = 'none';
        document.getElementById('login-step-1').style.display = 'block';
    }
    function showRegisterStep1() {
        document.getElementById('login-choice-step').style.display = 'none';
        document.getElementById('register-step-1').style.display = 'block';
    }
    function backToChoice() {
        document.getElementById('login-step-1').style.display = 'none';
        document.getElementById('register-step-1').style.display = 'none';
        document.getElementById('login-choice-step').style.display = 'block';
    }

    // メニューからのログイン呼び出し
    document.getElementById('nav-login-btn').addEventListener('click', function() {
        document.querySelector('[data-action="close-menu"]').click();
        backToChoice();
        authOverlay.classList.add('is-visible');
    });

    // 新規登録: コード送信
    function sendRegisterOtp() {
        const name = document.getElementById('regName').value;
        const email = document.getElementById('regEmail').value;
        const password = document.getElementById('regPassword').value;
        if(!name || !email || !password) return alert("すべての項目を入力してください。");
        
        fetch('?action=send_register_otp', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `email=${encodeURIComponent(email)}&name=${encodeURIComponent(name)}&password=${encodeURIComponent(password)}`
        }).then(res => {
            if (res.ok) {
                alert('認証メールを送信しました！フォルダを確認してください。');
                document.getElementById('register-step-1').style.display = 'none';
                document.getElementById('register-step-2').style.display = 'block';
            } else {
                res.text().then(alert);
            }
        });
    }

    // 新規登録: 確定
    function confirmRegister() {
        const code = document.getElementById('regCode').value;
        fetch('?action=confirm_register', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `code=${encodeURIComponent(code)}`
        }).then(res => {
            if(res.ok) {
                alert('登録が完了しました！');
                window.location.reload();
            } else res.text().then(alert);
        });
    }

    // ログイン実行
    function executeLogin() {
        const email = document.getElementById('loginEmail').value;
        const password = document.getElementById('loginPassword').value;
        fetch('?action=login', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
        }).then(res => {
            if(res.ok) {
                window.location.reload();
            } else res.text().then(alert);
        });
    }

    // --- メインアプリ ---
    (function () {
      var ALL_CATEGORY = "すべて";
      var DEFAULT_SORT = "newest";
      var categories = window.CAMPUS_BOARD_CATEGORIES;
      var recruitmentTypes = window.CAMPUS_BOARD_RECRUITMENT_TYPES;
      var viewRoot = document.getElementById("view-root");
      var sideMenu = document.getElementById("side-menu");
      var menuOverlay = document.getElementById("menu-overlay");
      var sideMenuLinks = document.querySelectorAll(".side-menu-link");
      
      var state = {
        posts: sortPosts((window.CAMPUS_BOARD_DB_POSTS || []).map(normalizePost), DEFAULT_SORT),
        viewFilter: "all", 
        currentView: "list", activeCategory: ALL_CATEGORY, searchTerm: "", sortOrder: DEFAULT_SORT, selectedPostId: null, isMenuOpen: false, notice: "",
        editPostId: null,
        postDraft: { category: categories[0], recruitmentType: recruitmentTypes[1] || recruitmentTypes[0], title: "", location: "", eventDate: "", eventEndDate: "", deadline: "", summary: "", description: "", max_participants: 0 }
      };

      ensureSelectedPost(); render();
      document.addEventListener("click", handleClick);
      document.addEventListener("submit", handleSubmit);
      document.addEventListener("input", handleInput);
      document.addEventListener("change", handleChange);

      function handleClick(event) {
        var mToggle = event.target.closest("[data-action='toggle-menu']"), mClose = event.target.closest("[data-action='close-menu']"), routeBtn = event.target.closest("[data-route]"), catBtn = event.target.closest("[data-category]"), detBtn = event.target.closest("[data-action='open-detail']"), rstFltBtn = event.target.closest("[data-action='reset-filter']"), rstLstBtn = event.target.closest("[data-action='reset-list-filters']"), clrSrchBtn = event.target.closest("[data-action='clear-search']"), upBtn = event.target.closest("[data-action='scroll-to-top']"), bckBtn = event.target.closest("[data-action='back-to-list']");
        var tglBtn = event.target.closest("[data-action='toggle-join']");
        var viewFltBtn = event.target.closest("[data-view-filter]"); 
        var editBtn = event.target.closest("[data-action='edit-post']");
        var delBtn = event.target.closest("[data-action='delete-post']");
        
        if (mToggle) { state.isMenuOpen = !state.isMenuOpen; syncMenuState(); return; }
        if (mClose) { state.isMenuOpen = false; syncMenuState(); return; }
        
        if (routeBtn) {
            if (routeBtn.dataset.route === 'create') {
                if (!window.CURRENT_USER) { state.isMenuOpen = false; syncMenuState(); alert("投稿を作成するにはログインが必要です！"); authOverlay.classList.add('is-visible'); return; }
                state.postDraft = { category: categories[0], recruitmentType: recruitmentTypes[0], title: "", location: "", eventDate: "", eventEndDate: "", deadline: "", summary: "", description: "", max_participants: 0 };
            }
            state.currentView = routeBtn.dataset.route; state.isMenuOpen = false; syncMenuState(); state.notice = ""; render(); return; 
        }

        if (editBtn) {
            var pId = Number(editBtn.dataset.postId);
            var targetPost = state.posts.find(p => p.id === pId);
            if (targetPost) {
                state.postDraft = Object.assign({}, targetPost);
                state.editPostId = pId;
                state.currentView = "edit";
                state.notice = "";
                render();
            }
            return;
        }

        if (delBtn) {
            if (confirm("この投稿を削除しますか？\n（参加者やコメントもすべて消去されます）")) {
                fetch('?action=delete_post', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `post_id=${Number(delBtn.dataset.postId)}` })
                .then(r => r.ok ? window.location.reload() : r.text().then(alert));
            }
            return;
        }

        if (viewFltBtn) { state.viewFilter = viewFltBtn.dataset.viewFilter; state.currentView = "list"; state.notice = ""; renderListView(); return; }
        if (tglBtn) { toggleJoin(Number(tglBtn.dataset.postId)); return; }
        if (catBtn) { state.activeCategory = catBtn.dataset.category; state.currentView = "list"; state.notice = ""; renderListView(); return; }
        if (detBtn) { state.selectedPostId = Number(detBtn.dataset.postId); state.currentView = "detail"; state.notice = ""; render(); return; }
        if (clrSrchBtn) { state.searchTerm = ""; state.currentView = "list"; state.notice = ""; renderListView(); return; }
        if (upBtn) { window.scrollTo({ top: 0, behavior: "smooth" }); return; }
        if (rstLstBtn || rstFltBtn) { state.activeCategory = ALL_CATEGORY; state.searchTerm = ""; state.sortOrder = DEFAULT_SORT; state.viewFilter = "all"; state.currentView = "list"; state.notice = ""; renderListView(); return; }
        if (bckBtn) { state.currentView = "list"; state.notice = ""; render(); }
      }

      function handleSubmit(event) {
        if (event.target.id === "post-form") { event.preventDefault(); createPost(new FormData(event.target)); }
        if (event.target.id === "edit-form") { event.preventDefault(); editPost(new FormData(event.target)); }
        if (event.target.id === "comment-form") { event.preventDefault(); createComment(new FormData(event.target)); }
      }

      function handleInput(event) {
        if (event.target.id === "search-input") { state.searchTerm = event.target.value; state.currentView = "list"; state.notice = ""; renderListView(); return; }
        if (event.target.closest("#post-form") || event.target.closest("#edit-form")) syncDraftField(event.target);
      }

      function handleChange(event) {
        if (event.target.id === "sort-select") { state.sortOrder = event.target.value || DEFAULT_SORT; state.currentView = "list"; state.notice = ""; renderListView(); return; }
        if (event.target.closest("#post-form") || event.target.closest("#edit-form")) syncDraftField(event.target);
      }

      function syncMenuState() {
        document.body.classList.toggle("menu-open", state.isMenuOpen);
        if (menuOverlay) { menuOverlay.classList.toggle("is-visible", state.isMenuOpen); menuOverlay.setAttribute("aria-hidden", String(!state.isMenuOpen)); }
        if (sideMenu) { sideMenu.classList.toggle("is-open", state.isMenuOpen); sideMenu.setAttribute("aria-hidden", String(!state.isMenuOpen)); }
      }

      function syncDraftField(target) { if (target.name && state.postDraft.hasOwnProperty(target.name)) state.postDraft[target.name] = target.value; }

      function normalizePost(post) {
        return {
          id: Number(post.id), category: post.category, recruitmentType: post.recruitmentType, title: post.title || "", author: post.author || "",
          location: post.location || "", eventDate: post.eventDate || "", eventEndDate: post.eventEndDate || "", deadline: post.deadline || "",
          createdAt: post.createdAt, summary: post.summary || "", description: post.description || "",
          max_participants: Number(post.max_participants) || 0, current_participants: Number(post.current_participants) || 0,
          is_joined_by_me: !!post.is_joined_by_me, user_id: Number(post.user_id),
          mapPoint: post.mapPoint || {}, comments: Array.isArray(post.comments) ? post.comments : []
        };
      }

      function sortPosts(posts, sortOrder) {
        return posts.slice().sort(function (a, b) {
          var aIsFull = (a.max_participants > 0 && a.current_participants >= a.max_participants) ? 1 : 0;
          var bIsFull = (b.max_participants > 0 && b.current_participants >= b.max_participants) ? 1 : 0;
          if (aIsFull !== bIsFull) return aIsFull - bIsFull;
          if (sortOrder === "deadline") {
            var da = !a.deadline ? Infinity : new Date(a.deadline).getTime();
            var db = !b.deadline ? Infinity : new Date(b.deadline).getTime();
            if (da !== db) return da - db;
          }
          return new Date(b.createdAt) - new Date(a.createdAt);
        });
      }

      function ensureSelectedPost() { if (state.posts.length && !state.posts.some(p => p.id === state.selectedPostId)) state.selectedPostId = sortPosts(state.posts, DEFAULT_SORT)[0].id; }
      
      function getVisiblePosts() {
        var filtered = state.posts;
        if (state.viewFilter === "my_posts") filtered = filtered.filter(p => p.user_id === window.CURRENT_USER_ID);
        else if (state.viewFilter === "my_joined") filtered = filtered.filter(p => p.is_joined_by_me);
        if (state.activeCategory !== ALL_CATEGORY) filtered = filtered.filter(p => p.category === state.activeCategory);
        var q = (state.searchTerm || "").toLowerCase().trim();
        if (q) filtered = filtered.filter(p => [p.title, p.summary, p.description, p.author, p.location, p.category].join(" ").toLowerCase().includes(q));
        return sortPosts(filtered, state.sortOrder);
      }
      function getSelectedPost() { return state.posts.find(p => p.id === state.selectedPostId) || null; }

      function render() {
        sideMenuLinks.forEach(l => l.classList.toggle("is-active", l.dataset.route === state.currentView || (l.dataset.route === 'list' && state.currentView === 'detail')));
        if (state.currentView === "create") { renderCreateView(); return; }
        if (state.currentView === "edit") { renderEditView(); return; }
        if (state.currentView === "detail") { renderDetailView(); return; }
        renderListView();
      }

      function renderListView() {
        var v = getVisiblePosts();
        viewRoot.innerHTML = [
          "<section class=\"section-card\" data-view=\"list\">", state.notice ? "<div class=\"notice\">" + escapeHTML(state.notice) + "</div>" : "",
          "<div class=\"section-header\"><h2>投稿一覧</h2></div>",
          "<div style=\"display:flex; gap:10px; margin-bottom:15px; border-bottom:1px solid var(--border); padding-bottom:15px;\">",
          "<button type=\"button\" class=\"secondary-button"+(state.viewFilter==='all'?' is-active':'')+"\" data-view-filter=\"all\" style=\""+(state.viewFilter==='all'?'background:var(--accent); color:white; border-color:var(--accent);':'')+"\">すべて</button>",
          (window.CURRENT_USER ? "<button type=\"button\" class=\"secondary-button"+(state.viewFilter==='my_joined'?' is-active':'')+"\" data-view-filter=\"my_joined\" style=\""+(state.viewFilter==='my_joined'?'background:var(--accent); color:white; border-color:var(--accent);':'')+"\">参加中</button>" : ""),
          (window.CURRENT_USER ? "<button type=\"button\" class=\"secondary-button"+(state.viewFilter==='my_posts'?' is-active':'')+"\" data-view-filter=\"my_posts\" style=\""+(state.viewFilter==='my_posts'?'background:var(--accent); color:white; border-color:var(--accent);':'')+"\">自分の投稿</button>" : ""),
          "</div>",
          "<div class=\"list-toolbar\"><div class=\"list-toolbar-grid\">",
          "<div class=\"tool-field\"><label>検索</label><input id=\"search-input\" type=\"search\" placeholder=\"キーワード\" value=\"" + escapeHTML(state.searchTerm) + "\"></div>",
          "<div class=\"tool-field\"><label>並び替え</label><select id=\"sort-select\"><option value=\"newest\"" + (state.sortOrder==='newest'?' selected':'') + ">新着順</option><option value=\"deadline\"" + (state.sortOrder==='deadline'?' selected':'') + ">締切順</option></select></div>",
          "<div class=\"tool-field tool-field-action\"><button type=\"button\" class=\"primary-button\" data-route=\"create\">投稿作成</button></div>",
          "</div><div class=\"toolbar-filter-group\"><label class=\"toolbar-label\">カテゴリ</label><div class=\"toolbar-chip-row\">",
          [ALL_CATEGORY].concat(categories).map(c => "<button type=\"button\" class=\"chip-button" + (c===state.activeCategory?" is-active":"") + "\" data-category=\"" + escapeHTML(c) + "\">" + escapeHTML(c) + "</button>").join(""),
          "</div></div></div>",
          "<div id=\"list-results\" style=\"margin-top:20px;\">",
          v.length ? "<div class=\"post-grid\">" + v.map(p => {
              var s = getEventStatus(p);
              var isFull = p.max_participants > 0 && p.current_participants >= p.max_participants;
              var opacityStyle = isFull ? " style=\"opacity:0.6;\"" : "";
              var pCount = p.current_participants + (p.max_participants > 0 ? " / " + p.max_participants : "") + "人";
              
              var authorActions = "";
              if (window.CURRENT_USER_ID === p.user_id) {
                  authorActions = "<button type=\"button\" class=\"link-button\" data-action=\"edit-post\" data-post-id=\""+p.id+"\" style=\"color:var(--muted); font-size:0.9em; padding-right:10px;\">編集</button>" +
                                  "<button type=\"button\" class=\"link-button\" data-action=\"delete-post\" data-post-id=\""+p.id+"\" style=\"color:#e74c3c; font-size:0.9em; padding-right:15px;\">削除</button>";
              }

              return "<article class=\"post-card\""+opacityStyle+"><div class=\"badge-row\"><span class=\"badge\">"+escapeHTML(p.category)+"</span><span class=\"status-badge status-badge-"+s.key+"\">"+s.label+"</span></div><h3 class=\"post-title\">"+escapeHTML(p.title)+"</h3><p class=\"post-summary\">"+escapeHTML(p.summary)+"</p><ul class=\"meta-list\">"+renderMetaItem("場所", p.location)+renderMetaItem("参加者", pCount)+renderMetaItem("締切", p.deadline || '未設定')+"</ul><div class=\"form-actions\">"+authorActions+"<button type=\"button\" class=\"link-button\" data-action=\"open-detail\" data-post-id=\""+p.id+"\">詳細を見る</button></div></article>";
          }).join("") + "</div>" : "<div class=\"empty-card\"><p>該当する投稿がありません。</p></div>",
          "</div></section>"
        ].join("");
      }

      function renderCreateView() {
        var d = new Date(); var minD = [d.getFullYear(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0')].join('-');
        viewRoot.innerHTML = [
          "<section class=\"form-card\">", state.notice ? "<div class=\"notice\">" + escapeHTML(state.notice) + "</div>" : "",
          "<div class=\"create-sticky-bar\"><button type=\"button\" class=\"secondary-button sticky-back-button\" data-action=\"back-to-list\">一覧へ戻る</button></div>",
          "<div class=\"form-header\"><h2>募集投稿の作成</h2></div>",
          "<form id=\"post-form\"><div class=\"field-grid\">",
          renderField("カテゴリ", "<select name=\"category\">" + categories.map(c => "<option value=\""+escapeHTML(c)+"\""+(c===state.postDraft.category?" selected":"")+">"+escapeHTML(c)+"</option>").join("") + "</select>"),
          renderField("募集種別", "<select name=\"recruitmentType\">" + recruitmentTypes.map(c => "<option value=\""+escapeHTML(c)+"\""+(c===state.postDraft.recruitmentType?" selected":"")+">"+escapeHTML(c)+"</option>").join("") + "</select>"),
          renderField("投稿タイトル", "<input type=\"text\" name=\"title\" required value=\""+escapeHTML(state.postDraft.title)+"\">", true),
          renderField("場所", "<input type=\"text\" name=\"location\" value=\""+escapeHTML(state.postDraft.location)+"\">"),
          renderField("上限人数", "<input type=\"number\" name=\"max_participants\" min=\"0\" value=\""+(state.postDraft.max_participants||0)+"\" placeholder=\"0で上限なし\">"),
          renderField("開催日", "<input type=\"date\" name=\"eventDate\" value=\""+escapeHTML(state.postDraft.eventDate)+"\">"),
          renderField("終了日", "<input type=\"date\" name=\"eventEndDate\" value=\""+escapeHTML(state.postDraft.eventEndDate)+"\">"),
          renderField("締切日", "<input type=\"date\" name=\"deadline\" min=\""+minD+"\" required value=\""+escapeHTML(state.postDraft.deadline)+"\">"),
          renderField("概要", "<textarea name=\"summary\" required>"+escapeHTML(state.postDraft.summary)+"</textarea>", true),
          renderField("詳細", "<textarea name=\"description\" required>"+escapeHTML(state.postDraft.description)+"</textarea>", true),
          "</div><div class=\"form-actions\"><button type=\"submit\" class=\"primary-button\">投稿する</button></div></form></section>"
        ].join("");
      }

      function renderEditView() {
        viewRoot.innerHTML = [
          "<section class=\"form-card\">", state.notice ? "<div class=\"notice\">" + escapeHTML(state.notice) + "</div>" : "",
          "<div class=\"create-sticky-bar\"><button type=\"button\" class=\"secondary-button sticky-back-button\" data-action=\"back-to-list\">一覧へ戻る</button></div>",
          "<div class=\"form-header\"><h2>募集投稿の編集</h2><p class=\"form-subtitle\">内容を修正して「更新する」を押してください。</p></div>",
          "<form id=\"edit-form\">",
          "<input type=\"hidden\" name=\"post_id\" value=\""+state.editPostId+"\">",
          "<div class=\"field-grid\">",
          renderField("カテゴリ", "<select name=\"category\">" + categories.map(c => "<option value=\""+escapeHTML(c)+"\""+(c===state.postDraft.category?" selected":"")+">"+escapeHTML(c)+"</option>").join("") + "</select>"),
          renderField("募集種別", "<select name=\"recruitmentType\">" + recruitmentTypes.map(c => "<option value=\""+escapeHTML(c)+"\""+(c===state.postDraft.recruitmentType?" selected":"")+">"+escapeHTML(c)+"</option>").join("") + "</select>"),
          renderField("投稿タイトル", "<input type=\"text\" name=\"title\" required value=\""+escapeHTML(state.postDraft.title)+"\">", true),
          renderField("場所", "<input type=\"text\" name=\"location\" value=\""+escapeHTML(state.postDraft.location)+"\">"),
          renderField("上限人数", "<input type=\"number\" name=\"max_participants\" min=\"0\" value=\""+(state.postDraft.max_participants||0)+"\" placeholder=\"0で上限なし\">"),
          renderField("開催日", "<input type=\"date\" name=\"eventDate\" value=\""+escapeHTML(state.postDraft.eventDate)+"\">"),
          renderField("終了日", "<input type=\"date\" name=\"eventEndDate\" value=\""+escapeHTML(state.postDraft.eventEndDate)+"\">"),
          renderField("締切日", "<input type=\"date\" name=\"deadline\" required value=\""+escapeHTML(state.postDraft.deadline)+"\">"),
          renderField("概要", "<textarea name=\"summary\" required>"+escapeHTML(state.postDraft.summary)+"</textarea>", true),
          renderField("詳細", "<textarea name=\"description\" required>"+escapeHTML(state.postDraft.description)+"</textarea>", true),
          "</div><div class=\"form-actions\"><button type=\"submit\" class=\"primary-button\">更新する</button></div></form></section>"
        ].join("");
      }

      function renderDetailView() {
        var p = getSelectedPost(); if (!p) return;
        var s = getEventStatus(p);
        
        var isFull = p.max_participants > 0 && p.current_participants >= p.max_participants;
        var pCount = p.current_participants + (p.max_participants > 0 ? " / " + p.max_participants : "") + "人";
        
        var joinBtn = "";
        if (window.CURRENT_USER) {
            if (p.is_joined_by_me) joinBtn = "<button type=\"button\" class=\"primary-button\" style=\"background:var(--warning);\" data-action=\"toggle-join\" data-post-id=\""+p.id+"\">参加を取り消す</button>";
            else if (isFull) joinBtn = "<button type=\"button\" class=\"secondary-button\" disabled>満員です</button>";
            else joinBtn = "<button type=\"button\" class=\"primary-button\" style=\"background:var(--accent-strong);\" data-action=\"toggle-join\" data-post-id=\""+p.id+"\">参加する！</button>";
        } else {
            joinBtn = "<button type=\"button\" class=\"primary-button\" onclick=\"document.getElementById('nav-login-btn').click();\">ログインして参加</button>";
        }

        var authorActions = "";
        if (window.CURRENT_USER_ID === p.user_id) {
            authorActions = "<button type=\"button\" class=\"secondary-button\" data-action=\"edit-post\" data-post-id=\""+p.id+"\">編集</button>" +
                            "<button type=\"button\" class=\"secondary-button\" data-action=\"delete-post\" data-post-id=\""+p.id+"\" style=\"color:#e74c3c; border-color:#fadbd8; background:#fdedec;\">削除</button>";
        }

        viewRoot.innerHTML = [
          "<div class=\"detail-layout\"><section class=\"detail-card\">",
          "<div class=\"detail-header\"><div><div class=\"badge-row\"><span class=\"badge\">"+escapeHTML(p.category)+"</span><span class=\"status-badge status-badge-"+s.key+"\">"+s.label+"</span></div>",
          "<h2 class=\"detail-title\">"+escapeHTML(p.title)+"</h2></div>",
          "<div class=\"detail-actions\">"+joinBtn+authorActions+"<button type=\"button\" class=\"secondary-button\" data-action=\"back-to-list\">一覧へ戻る</button></div></div>",
          "<div class=\"meta-grid\"><div class=\"detail-meta-card\"><h3>情報</h3><ul class=\"detail-meta-list\">",
          renderMetaItem("投稿者", p.author), renderMetaItem("場所", p.location), renderMetaItem("参加者", pCount), renderMetaItem("締切", p.deadline || "未設定"),
          "</ul></div></div><div class=\"detail-content\"><p>"+escapeHTML(p.description).replace(/\n/g, '<br>')+"</p></div></section>",
          "<section class=\"comments-card\"><h3>コメント</h3>",
          p.comments.length ? "<div class=\"comment-list\">" + p.comments.map(c => "<article class=\"comment-card\"><div class=\"comment-card-header\"><strong>"+escapeHTML(c.name)+"</strong><span class=\"comment-time\">"+(new Date(c.createdAt).toLocaleString("ja-JP"))+"</span></div><p>"+escapeHTML(c.text).replace(/\n/g, '<br>')+"</p></article>").join("") + "</div>" : "<p style='color:var(--muted);'>まだコメントはありません。</p>",
          "<form id=\"comment-form\" style=\"margin-top:20px;\"><input type=\"hidden\" name=\"postId\" value=\""+p.id+"\"><div class=\"field-grid\">",
          renderField("コメントを追加", "<textarea name=\"text\" required></textarea>", true),
          "</div><div class=\"form-actions\"><button type=\"submit\" class=\"primary-button\">送信</button></div></form></section></div>"
        ].join("");
      }

      function renderField(label, control, isFull) { return "<div class=\"field"+(isFull?" is-full":"")+"\"><label>"+escapeHTML(label)+"</label>"+control+"</div>"; }
      function renderMetaItem(l, v) { return "<li class=\"detail-meta-item\"><span class=\"meta-label\">"+escapeHTML(l)+"</span><strong>"+escapeHTML(v||"未設定")+"</strong></li>"; }
      function escapeHTML(str) { return String(str||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;"); }
      
      function getEventStatus(post) {
        var t = [new Date().getFullYear(), String(new Date().getMonth()+1).padStart(2,'0'), String(new Date().getDate()).padStart(2,'0')].join('-');
        if (post.deadline && t > post.deadline) return { key: "ended", label: "終了" };
        if (!post.eventDate) return { key: "open", label: "募集中" };
        if (t < post.eventDate) return { key: "before", label: "開催前" };
        return { key: "active", label: "開催中" };
      }

      function toggleJoin(id) {
        if (!window.CURRENT_USER) { alert("参加するにはログインが必要です！"); document.getElementById('nav-login-btn').click(); return; }
        fetch('?action=toggle_join', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `post_id=${id}` }).then(r => r.ok ? window.location.reload() : r.text().then(alert));
      }

      function createPost(formData) {
        if (!window.CURRENT_USER) return alert("ログインが必要です");
        fetch('?action=create_post', { method: 'POST', body: formData }).then(r => r.ok ? window.location.reload() : r.text().then(alert));
      }

      function editPost(formData) {
        if (!window.CURRENT_USER) return alert("ログインが必要です");
        fetch('?action=edit_post', { method: 'POST', body: formData }).then(r => r.ok ? window.location.reload() : r.text().then(alert));
      }

      function createComment(formData) {
        if (!window.CURRENT_USER) { alert("コメントするにはログインが必要です！"); document.getElementById('nav-login-btn').click(); return; }
        fetch('?action=create_comment', { method: 'POST', body: formData }).then(r => r.ok ? window.location.reload() : r.text().then(alert));
      }
    })();
  </script>
</body>
</html>