<?php
// Markdown parser
//   # / ## / ### ...   -> headers
//   **bold line**       -> instruction text
//   N. ... _|answer|_ ...           -> fill-in question 
//   N. prompt — |OPT1| / OPT2       -> select question 

function parseMarkdown($text) {
  $lines = preg_split('/\r\n|\r|\n/', $text);
  $blocks = [];
  $title = null;

  foreach ($lines as $raw) {
    $line = trim($raw);
    if ($line === '') continue;
    if (preg_match('/^-{3,}$/', $line)) continue;

    if (preg_match('/^(#{1,6})\s+(.*)/', $line, $m)) {
      $level = strlen($m[1]);
      $htext = trim($m[2]);
      if ($title === null && $level === 1) $title = $htext;
      $blocks[] = ['type' => 'header', 'level' => $level, 'text' => $htext];
      continue;
    }

    if (preg_match('/^\*\*(.+)\*\*$/', $line, $m)) {
      $blocks[] = ['type' => 'instr', 'text' => trim($m[1])];
      continue;
    }

    if (preg_match('/^(\d+)\.\s+(.*)/', $line, $m)) {
      $num = (int)$m[1];
      $content = $m[2];

      if (preg_match_all('/_\|(.*?)\|_/', $content, $fm)) {
        $answers = array_map('trim', $fm[1]);
        $i = 0;
        $template = preg_replace_callback('/_\|(.*?)\|_/', function () use (&$i) {
          return '{' . ($i++) . '}';
        }, $content);
        $blocks[] = ['type' => 'question', 'qtype' => 'fill', 'num' => $num,
                      'template' => $template, 'answers' => $answers];
        continue;
      }

      if (preg_match('/^(.*?)[\-\x{2013}\x{2014}]\s*\|([^|]+)\|\s*\/\s*(.+)$/u', $content, $sm)) {
        $prompt = trim($sm[1], " \t-\x{2013}\x{2014}");
        $answer = trim($sm[2]);
        $opt2 = trim($sm[3]);
        $blocks[] = ['type' => 'question', 'qtype' => 'select', 'num' => $num,
                      'prompt' => $prompt, 'options' => [$answer, $opt2], 'answer' => $answer];
        continue;
      }

      if (preg_match('/^(.*?)[\-\x{2013}\x{2014}]\s*([^\/]+?)\s*\/\s*\|([^|]+)\|\s*$/u', $content, $sm)) {
        $prompt = trim($sm[1], " \t-\x{2013}\x{2014}");
        $opt1 = trim($sm[2]);
        $answer = trim($sm[3]);
        $blocks[] = ['type' => 'question', 'qtype' => 'select', 'num' => $num,
                      'prompt' => $prompt, 'options' => [$opt1, $answer], 'answer' => $answer];
        continue;
      }

      $blocks[] = ['type' => 'text', 'num' => $num, 'text' => $content];
      continue;
    }
  }

  if ($title === null) $title = 'Untitled Quiz';
  return ['title' => $title, 'blocks' => $blocks];
}

// Strip character whitelist 
function sanitizeInput($s) {
  $s = (string)$s;
  $s = preg_replace('/[^\p{L}\p{M}\p{N}\s.]/u', '', $s);
  $s = trim($s);
  $s = mb_substr($s, 0, 200, 'UTF-8');
  return $s;
}

// CSRF slop
function csrfToken() {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}

function csrfCheck($token) {
  return isset($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function norm($s) {
  $s = trim((string)$s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}


function gradeSubmission($blocks, $post) {
  $score = 0;
  $total = 0;
  $results = [];
  foreach ($blocks as $idx => $b) {
    if ($b['type'] !== 'question') continue;
    if ($b['qtype'] === 'select') {
      $total++;
      $given = $post["q{$idx}"] ?? '';
      $ok = ($given === $b['answer']);
      if ($ok) $score++;
      $results[$idx] = ['ok' => [$ok], 'given' => [$given]];
    } else {
      $okArr = [];
      $givenArr = [];
      foreach ($b['answers'] as $i => $ans) {
        $total++;
        $given = $post["q{$idx}_{$i}"] ?? '';
        $ok = (norm($given) === norm($ans));
        if ($ok) $score++;
        $okArr[] = $ok;
        $givenArr[] = $given;
      }
      $results[$idx] = ['ok' => $okArr, 'given' => $givenArr];
    }
  }
  return [$score, $total, $results];
}


function renderBlock($idx, $b, $submitted, $results, $readonly = false) {
  ob_start();
  if ($b['type'] === 'header') {
    if ($b['level'] >= 2) {
      $tag = $b['level'] === 2 ? 'h2' : 'h3';
      echo "<{$tag}>" . htmlspecialchars($b['text'], ENT_QUOTES, 'UTF-8') . "</{$tag}>";
    }
  } elseif ($b['type'] === 'instr') {
    echo '<p class="instr">' . htmlspecialchars($b['text'], ENT_QUOTES, 'UTF-8') . '</p>';
  } elseif ($b['type'] === 'text') {
    echo '<div class="question"><strong>' . $b['num'] . '.</strong> ' . htmlspecialchars($b['text'], ENT_QUOTES, 'UTF-8') . '</div>';
  } elseif ($b['type'] === 'question') {
    echo '<div class="question"><strong>' . $b['num'] . '.</strong> ';
    if ($b['qtype'] === 'select') {
      echo htmlspecialchars($b['prompt'], ENT_QUOTES, 'UTF-8') . ' — ';
      $given = $submitted ? $results[$idx]['given'][0] : '';
      $dis = $readonly ? 'disabled' : '';
      foreach ($b['options'] as $opt) {
        $checked = ($given === $opt) ? 'checked' : '';
        echo '<label class="opt"><input type="radio" name="q' . $idx . '" value="' . htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') . '" ' . $checked . ' ' . $dis . '> ' . htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') . '</label>';
      }
      if ($submitted) {
        if ($results[$idx]['ok'][0]) {
          echo '<span class="correction" style="color:green;">✓</span>';
        } else {
          echo '<span class="correction">✗ correct: ' . htmlspecialchars($b['answer'], ENT_QUOTES, 'UTF-8') . '</span>';
        }
      }
    } else {
      // Escape the uploaded md before subing input, then pass htmlspecialchars
      $html = htmlspecialchars($b['template'], ENT_QUOTES, 'UTF-8');
      foreach ($b['answers'] as $i => $ans) {
        $name = "q{$idx}_{$i}";
        $val = '';
        $cls = '';
        $mark = '';
        if ($submitted) {
          $val = htmlspecialchars($results[$idx]['given'][$i], ENT_QUOTES, 'UTF-8');
          $ok = $results[$idx]['ok'][$i];
          $cls = $ok ? 'ok' : 'bad';
          if (!$ok) {
            $disp = $ans === '' ? '(blank)' : htmlspecialchars($ans, ENT_QUOTES, 'UTF-8');
            $mark = " <span class=\"correction\">correct: {$disp}</span>";
          }
        }
        $dis = $readonly ? 'readonly' : '';
        $input = "<input type=\"text\" name=\"{$name}\" value=\"{$val}\" class=\"blank {$cls}\" autocomplete=\"off\" maxlength=\"200\" {$dis}>{$mark}";
        $html = str_replace('{' . $i . '}', $input, $html);
      }
      echo $html;
    }
    echo '</div>';
  }
  return ob_get_clean();
}