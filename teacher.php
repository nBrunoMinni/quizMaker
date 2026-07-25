<?php
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';


if (isset($_GET['logout'])) {
  session_unset();
  session_destroy();
  header("Location: teacher.php");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !isset($_FILES['mdFile'])) {
  // Throttle state keyed by IP, not cookie.
  $ip = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
  if ($ip === false) $ip = "\0";

  $stmt = $dbc->prepare("SELECT 1 FROM loginAttempts WHERE ip = ? AND locked_until > NOW()");
  $stmt->bind_param('s', $ip);
  $stmt->execute();
  $locked = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();

  if (!csrfCheck($_POST['csrf'] ?? '')) {
    $loginError = 'Your session expired — please try again.';
  } elseif ($locked) {
    http_response_code(429);
    $loginError = 'Too many attempts. Please wait and try again.';
  } elseif (password_verify($_POST['password'], ADMIN_PASSWORD_HASH)) {
    session_regenerate_id(true);
    $_SESSION['teacher'] = true;
    $stmt = $dbc->prepare("DELETE FROM loginAttempts WHERE ip = ?");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
  } else {

    // 1 and 2 fails free, then suspends up to 1h.
    $stmt = $dbc->prepare(
      "INSERT INTO loginAttempts (ip, fails, locked_until) VALUES (?, 1, NULL)
       ON DUPLICATE KEY UPDATE
         fails = fails + 1,
         locked_until = IF(fails >= 3,
                           DATE_ADD(NOW(), INTERVAL LEAST(POW(2, fails - 2), 3600) SECOND),
                           NULL)"
    );
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
    $loginError = 'Incorrect password.';
  }
}

if (empty($_SESSION['teacher'])) {
?>
  <!DOCTYPE html>
  <html>

  <head>
    <meta charset="UTF-8">
    <title>Teacher Login</title>
    <link rel="stylesheet" href="style.css">
  </head>

  <body class="narrow">
    <h1>Teacher Login</h1>
    <?php if (!empty($loginError)): ?><p class="error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="password" name="password" class="blockInput" placeholder="Admin password" required autofocus>
      <button type="submit">Log in</button>
    </form>
  </body>

  </html>
<?php
  exit;
}


// Logged in
$action = $_GET['action'] ?? '';

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['mdFile'])) {
  if (!csrfCheck($_POST['csrf'] ?? '')) {
    die("Invalid request — please go back and try uploading again.");
  }
  if ($_FILES['mdFile']['error'] !== UPLOAD_ERR_OK) {
    die("Upload failed.");
  }
  if ($_FILES['mdFile']['size'] > 300 * 1024) {
    die("File too large (max 300 KB).");
  }
  $ext = strtolower(pathinfo($_FILES['mdFile']['name'], PATHINFO_EXTENSION));
  if ($ext !== 'md') {
    die("Only .md files are allowed.");
  }
  $content = file_get_contents($_FILES['mdFile']['tmp_name']);
  $parsed = parseMarkdown($content);
  $stmt = $dbc->prepare("INSERT INTO quizzes (title, data) VALUES (?, ?)");
  $json = json_encode($parsed, JSON_UNESCAPED_UNICODE);
  $stmt->bind_param('ss', $parsed['title'], $json);
  $stmt->execute();
  $stmt->close();
  header("Location: teacher.php");
  exit;
}

// View submission
if ($action === 'view' && isset($_GET['sub'])) {
  $subId = (int)$_GET['sub'];
  $stmt = $dbc->prepare("SELECT s.student_name, s.answers, s.score, s.total, s.submitted_at, q.title, q.data
                          FROM submissions s JOIN quizzes q ON q.id = s.quiz_id
                          WHERE s.id = ?");
  $stmt->bind_param('i', $subId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) die("Submission not found.");

  $blocks = json_decode($row['data'], true)['blocks'];
  $storedPost = json_decode($row['answers'], true);
  [$score, $total, $results] = gradeSubmission($blocks, $storedPost);
?>
  <!DOCTYPE html>
  <html lang="es">

  <head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css">
  </head>

  <body>
    <a class="back" href="teacher.php">&larr; Back to dashboard</a>
    <h1><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="score">
      <?= htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8') ?> — <?= $score ?> / <?= $total ?>
      <div class="meta">Submitted <?= htmlspecialchars($row['submitted_at'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php foreach ($blocks as $idx => $b): ?>
      <?= renderBlock($idx, $b, true, $results, true) ?>
    <?php endforeach; ?>
  </body>

  </html>
<?php
  exit;
}

// Teacher dash 
$quizzes = $dbc->query("SELECT id, title, created_at FROM quizzes ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Teacher Dashboard</title>
  <link rel="stylesheet" href="style.css">
</head>

<body>
  <a class="logout" href="?logout=1">Log out</a>
  <h1>Teacher Dashboard</h1>
  <p>Drag and drop a <code>.md</code> file here, or click to choose one.</p>

  <form id="uploadForm" method="post" action="?action=upload" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <div id="dropZone">Drop .md file here</div>
    <input type="file" name="mdFile" id="fileInput" accept=".md" style="display:none">
  </form>

  <h2>Quizzes</h2>
  <?php if ($quizzes && $quizzes->num_rows > 0): ?>
    <?php while ($q = $quizzes->fetch_assoc()): ?>
      <div class="quizBlock">
        <div class="quizTitle">
          <?= htmlspecialchars($q['title'], ENT_QUOTES, 'UTF-8') ?>
          <small>(uploaded <?= htmlspecialchars($q['created_at'], ENT_QUOTES, 'UTF-8') ?>)</small>
          — <a class="takeLink" href="quiz.php?quiz=<?= (int)$q['id'] ?>" target="_blank">quiz link</a>
        </div>
        <?php
        $stmt = $dbc->prepare("SELECT id, student_name, score, total, submitted_at FROM submissions WHERE quiz_id = ? ORDER BY submitted_at DESC");
        $stmt->bind_param('i', $q['id']);
        $stmt->execute();
        $subs = $stmt->get_result();
        ?>
        <?php if ($subs->num_rows > 0): ?>
          <ul class="submissions">
            <?php while ($s = $subs->fetch_assoc()): ?>
              <li>
                <a href="?action=view&sub=<?= (int)$s['id'] ?>">
                  <?= htmlspecialchars($s['student_name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                — <?= (int)$s['score'] ?>/<?= (int)$s['total'] ?>
                <small>(<?= htmlspecialchars($s['submitted_at'], ENT_QUOTES, 'UTF-8') ?>)</small>
              </li>
            <?php endwhile; ?>
          </ul>
        <?php else: ?>
          <div style="padding-left:20px;"><small>No submissions yet.</small></div>
        <?php endif; ?>
        <?php $stmt->close(); ?>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <p><small>No quizzes uploaded yet.</small></p>
  <?php endif; ?>

  <script>
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const form = document.getElementById('uploadForm');

    dropZone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
      if (fileInput.files.length) form.submit();
    });

    dropZone.addEventListener('dragover', e => {
      e.preventDefault();
      dropZone.classList.add('drag');
    });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag'));
    dropZone.addEventListener('drop', e => {
      e.preventDefault();
      dropZone.classList.remove('drag');
      if (e.dataTransfer.files.length) {
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        fileInput.files = dt.files;
        form.submit();
      }
    });
  </script>
</body>

</html>