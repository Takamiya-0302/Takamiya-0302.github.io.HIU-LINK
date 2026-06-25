<?php
// ==========================================
// ⚙️ 管理者専用バックエンド処理（PHP + SQLite）
// ==========================================
session_start();

// 1. 強力なセキュリティブロック（管理者以外は即座に弾く）
if (!isset($_SESSION['email']) || $_SESSION['email'] !== 'xiudougaogong@gmail.com') {
    header("Location: index.php");
    exit;
}

// 2. データベース接続（public_htmlの外側、インデックスと同じ安全なパス）
$db_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'HIU-LINK.db';
$pdo = new PDO('sqlite:' . $db_path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$action = $_GET['action'] ?? '';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// 3. 管理者権限による強制削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 投稿の強制削除
    if ($action === 'adm_delete_post') {
        $pid = (int)($_POST['post_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmt->execute([$pid]);
        header("Location: admin.php?msg=post_deleted"); exit;
    }
    // コメントの強制削除
    if ($action === 'adm_delete_comment') {
        $cid = (int)($_POST['comment_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
        $stmt->execute([$cid]);
        header("Location: admin.php?msg=comment_deleted"); exit;
    }
    // ユーザーの強制削除（退学・規約違反対応など）
    if ($action === 'adm_delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        // 自分自身を消してしまわないための安全弁
        if ($uid === $_SESSION['user_id']) {
            header("Location: admin.php?err=cannot_delete_self"); exit;
        }
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        header("Location: admin.php?msg=user_deleted"); exit;
    }
}

// 4. ダッシュボード用データ集計
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_posts = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$total_comments = $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn();
$total_joins = $pdo->query("SELECT COUNT(*) FROM participants")->fetchColumn();

// 管理一覧用データの取得
$users = $pdo->query("SELECT * FROM users ORDER BY createdAt DESC")->fetchAll(PDO::FETCH_ASSOC);
$posts = $pdo->query("SELECT p.*, u.name AS author_name FROM posts p JOIN users u ON p.user_id = u.id ORDER BY p.createdAt DESC")->fetchAll(PDO::FETCH_ASSOC);
$comments = $pdo->query("SELECT c.*, u.name AS commenter_name, p.title AS post_title FROM comments c JOIN users u ON c.user_id = u.id JOIN posts p ON c.post_id = p.id ORDER BY c.createdAt DESC")->fetchAll(PDO::FETCH_ASSOC);

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>HIU Link - 管理者ダッシュボード</title>
  <style>
    :root {
      --bg: #f4f7f1; --bg-accent: #eef4fb; --card: #ffffff; --card-muted: #f7f9fc;
      --text: #17324d; --muted: #5a6c7d; --border: #d8e0e8; --accent: #0f766e;
      --accent-strong: #115e59; --accent-soft: #d8f3ef; --warning: #c27b1d;
      --danger: #e74c3c; --danger-soft: #fdedec;
      --shadow: 0 18px 40px rgba(32, 66, 91, 0.08); --radius: 20px;
      --content-max-width: 1120px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; font-family: "Segoe UI", "Yu Gothic UI", "Hiragino Sans", sans-serif; color: var(--text);
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); min-height: 100vh; padding: 30px 20px;
    }
    .admin-container { max-width: var(--content-max-width); margin: 0 auto; }
    .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; color: #fff; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 20px; }
    .admin-title { margin: 0; font-size: 1.8rem; }
    
    /* 統計カード */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .stat-card { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: var(--radius); padding: 20px; color: #fff; }
    .stat-card span { display: block; color: #94a3b8; font-size: 0.9rem; margin-bottom: 8px; }
    .stat-card strong { font-size: 2rem; color: #38bdf8; }

    /* メイン管理セクション */
    .management-section { background: var(--card); border-radius: var(--radius); padding: 25px; box-shadow: var(--shadow); margin-bottom: 30px; }
    .section-title { margin: 0 0 20px; font-size: 1.3rem; display: flex; align-items: center; gap: 10px; border-left: 5px solid var(--accent); padding-left: 10px; }
    
    /* データテーブル */
    .table-wrapper { overflow-x: auto; margin-top: 15px; }
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.95rem; }
    th, td { padding: 14px; border-bottom: 1px solid var(--border); }
    th { background-color: var(--card-muted); font-weight: 700; color: var(--muted); }
    tr:hover { background-color: rgba(244, 247, 241, 0.4); }

    /* ボタン・バッジ */
    .btn-back { background: rgba(255,255,255,0.1); color: #fff; padding: 10px 20px; border-radius: 999px; text-decoration: none; font-weight: bold; border: 1px solid rgba(255,255,255,0.2); transition: 0.2s; }
    .btn-back:hover { background: rgba(255,255,255,0.2); }
    .btn-del { background: var(--danger-soft); color: var(--danger); border: 1px solid #fadbd8; padding: 6px 12px; border-radius: 10px; font-weight: bold; cursor: pointer; transition: 0.2s; }
    .btn-del:hover { background: var(--danger); color: #fff; }
    
    .alert { padding: 15px; border-radius: 12px; margin-bottom: 25px; font-weight: bold; }
    .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
  </style>
</head>
<body>

  <div class="admin-container">
    <header class="admin-header">
      <div>
        <h1 class="admin-title">⚙️ HIU Link 管理者ダッシュボード</h1>
        <p style="margin: 5px 0 0; color: #94a3b8;">サインイン中の管理者: <?= h($_SESSION['user_name']) ?> (最高権限)</p>
      </div>
      <a href="index.php" class="btn-back">← アプリ画面へ戻る</a>
    </header>

    <?php if ($msg === 'post_deleted'): ?><div class="alert alert-success">投稿を強制削除しました。</div><?php endif; ?>
    <?php if ($msg === 'comment_deleted'): ?><div class="alert alert-success">コメントを強制削除しました。</div><?php endif; ?>
    <?php if ($msg === 'user_deleted'): ?><div class="alert alert-success">ユーザーアカウントをシステムから追放しました。</div><?php endif; ?>
    <?php if ($err === 'cannot_delete_self'): ?><div class="alert alert-danger">エラー：自分自身のアカウントは削除できません。</div><?php endif; ?>

    <div class="stats-grid">
      <div class="stat-card"><span>総登録ユーザー数</span><strong><?= $total_users ?></strong> 人</div>
      <div class="stat-card"><span>総募集投稿数</span><strong><?= $total_posts ?></strong> 件</div>
      <div class="stat-card"><span>総コメント数</span><strong><?= $total_comments ?></strong> 件</div>
      <div class="stat-card"><span>総参加登録エンゲージメント</span><strong><?= $total_joins ?></strong> 件</div>
    </div>

    <section class="management-section">
      <h2 class="section-title">👤 登録ユーザーアカウント管理</h2>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>ID</th><th>ユーザー名</th><th>メールアドレス</th><th>登録日時</th><th>操作</th></tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td><?= $u['id'] ?></td>
                <td><strong><?= h($u['name']) ?></strong></td>
                <td><?= h($u['email']) ?></td>
                <td><?= date('Y/m/d H:i', strtotime($u['createdAt'])) ?></td>
                <td>
                  <form action="?action=adm_delete_user" method="POST" onsubmit="return confirm('このユーザーを追放しますか？\n作成した投稿やコメントも自動で全消去されます。');" style="margin:0;">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn-del" <?= $u['id'] == $_SESSION['user_id'] ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : '' ?>>アカウント削除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="management-section">
      <h2 class="section-title">📌 募集・告知投稿の集中管理</h2>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>ID</th><th>カテゴリ</th><th>タイトル</th><th>投稿者</th><th>上限人数</th><th>作成日時</th><th>操作</th></tr>
          </thead>
          <tbody>
            <?php foreach ($posts as $p): ?>
              <tr>
                <td><?= $p['id'] ?></td>
                <td><span style="background:#e8f0fe; color:var(--accent); padding:4px 8px; border-radius:6px; font-weight:bold; font-size:0.85em;"><?= h($p['category']) ?></span></td>
                <td><strong><?= h($p['title']) ?></strong></td>
                <td><?= h($p['author_name']) ?></td>
                <td><?= $p['max_participants'] > 0 ? $p['max_participants'].'人' : '上限なし' ?></td>
                <td><?= date('Y/m/d H:i', strtotime($p['createdAt'])) ?></td>
                <td>
                  <form action="?action=adm_delete_post" method="POST" onsubmit="return confirm('この投稿を強制削除しますか？');" style="margin:0;">
                    <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn-del">強制削除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($posts)): ?><tr><td colspan="7" style="text-align:center; color:var(--muted);">投稿がありません。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="management-section">
      <h2 class="section-title">💬 タイムラインコメント管理</h2>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>ID</th><th>対象投稿</th><th>コメント者</th><th>内容</th><th>投稿日時</th><th>操作</th></tr>
          </thead>
          <tbody>
            <?php foreach ($comments as $c): ?>
              <tr>
                <td><?= $c['id'] ?></td>
                <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--muted);"><?= h($c['post_title']) ?></td>
                <td><strong><?= h($c['commenter_name']) ?></strong></td>
                <td><?= h($c['text']) ?></td>
                <td><?= date('Y/m/d H:i', strtotime($c['createdAt'])) ?></td>
                <td>
                  <form action="?action=adm_delete_comment" method="POST" onsubmit="return confirm('このコメントを強制削除しますか？');" style="margin:0;">
                    <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn-del">強制削除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($comments)): ?><tr><td colspan="6" style="text-align:center; color:var(--muted);">コメントがありません。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

</body>
</html>