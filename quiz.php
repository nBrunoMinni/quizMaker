<?php
// No session: students have no credentials, so no cookie and no CSRF token.
require __DIR__ . '/config.php';
require __DIR__ . '/functions.php';

$quizId = isset($_GET['quiz']) ? (int)$_GET['quiz'] : 0;
if ($quizId <= 0) {
  die("No quiz specified.");
}

$stmt = $dbc->prepare("SELECT title, data FROM quizzes WHERE id = ?");
$stmt->bind_param('i', $quizId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
  die("Quiz not found.");
}

$parsed = json_decode($res['data'], true);
$blocks = $parsed['blocks'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($_POST as $k => $v) {
    $_POST[$k] = sanitizeInput($v);
  }
}

$submitted = false;
$score = 0; $total = 0; $results = [];
$nameError = '';
$studentName = trim($_POST['studentName'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($studentName === '') {
    $nameError = 'Please enter your name before submitting.';
  } else {
    [$score, $total, $results] = gradeSubmission($blocks, $_POST);
    $submitted = true;

    $answersJson = json_encode($_POST, JSON_UNESCAPED_UNICODE);
    $stmt = $dbc->prepare("INSERT INTO submissions (quiz_id, student_name, answers, score, total) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('issii', $quizId, $studentName, $answersJson, $score, $total);
    $stmt->execute();
    $stmt->close();
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<h1><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></h1>

<?php if ($submitted): ?>

  <div class="score">Thanks, <?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?> — your score: <?= $score ?> / <?= $total ?></div>

<?php else: ?>

  <?php if ($nameError): ?>
    <p class="error"><?= htmlspecialchars($nameError, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

  <form method="post" action="?quiz=<?= $quizId ?>">
    <div class="nameField">
      <label>Your name:<br><input type="text" name="studentName" class="blockInput" maxlength="200" value="<?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?>" required></label>
    </div>

    <?php foreach ($blocks as $idx => $b): ?>
      <?= renderBlock($idx, $b, false, $results, false) ?>
    <?php endforeach; ?>

    <button type="submit">Submit &amp; Grade</button>
  </form>

<?php endif; ?>

<script>
// Mirrors server whitelist
document.querySelectorAll('input[type="text"]').forEach(function (inp) {
  inp.addEventListener('input', function () {
    inp.value = inp.value.replace(/[^\p{L}\p{M}\p{N}\s.]/gu, '');
  });
});
</script>
</body>
</html>