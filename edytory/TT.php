<?php
session_start();

/* ---------- 1. LOGOWANIE ---------- */
$USER = 'redakcja';
// wygeneruj raz: php -r "echo password_hash('twoje_haslo', PASSWORD_DEFAULT);"
$HASH = '$2y$10$HQJZZw1bV5gdieFpH.egu.WLJ2P2cueNQamH.euS0R.fvRJ/goA5y';

$u = $_SERVER['PHP_AUTH_USER'] ?? '';
$p = $_SERVER['PHP_AUTH_PW'] ?? '';
if (!hash_equals($USER, $u) || !password_verify($p, $HASH)) {
    header('WWW-Authenticate: Basic realm="Panel redakcji"');
    header('HTTP/1.0 401 Unauthorized');
    exit('Brak dostępu');
}

/* ---------- 2. CSRF ---------- */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrf, $_POST['csrf'] ?? '')) {
    http_response_code(403);
    exit('Nieprawidłowy token formularza');
}

/* ---------- 3. BEZPIECZNA ŚCIEŻKA ---------- */
// Dozwolone tylko istniejące pliki .tsv wewnątrz katalogu assets
function safePath(string $path): ?string
{
    $base = realpath(__DIR__ . '/assets');
    $real = realpath(__DIR__ . '/' . $path);
    if ($base === false || $real === false) return null;
    if (strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return null;
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'tsv') return null;
    return $real;
}

function readTSV($filename)
{
    $data = [];
    if (($handle = fopen($filename, "r")) !== false) {
        while (($line = fgetcsv($handle, 0, "\t")) !== false) {
            $data[] = array_pad($line, 3, '');
        }
        fclose($handle);
    }
    return $data;
}

function writeTSV($filename, $data)
{
    $handle = fopen($filename, "c");
    if ($handle === false) return;
    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    foreach ($data as $line) {
        fputcsv($handle, $line, "\t");
    }
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

$requested = $_POST["filepath"] ?? "assets/TT/TT.tsv";
$filepath  = safePath($requested);
if ($filepath === null) {
    http_response_code(400);
    exit('Niedozwolona ścieżka pliku');
}

$data = readTSV($filepath);

$editIndex = isset($_POST["edit_index"]) ? (int) $_POST["edit_index"] : -1;
$editMode  = $editIndex >= 0 && $editIndex < count($data);
$editRow   = $editMode ? $data[$editIndex] : ["", "", ""];

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["column1"], $_POST["column2"], $_POST["column3"])) {

    $newRow = [$_POST["column1"], $_POST["column2"], $_POST["column3"]];
    if ($editMode) {
        $data[$editIndex] = $newRow;
    } else {
        $data[] = $newRow;
    }
    writeTSV($filepath, $data);

    $editIndex = -1;
    $editMode  = false;
    $editRow   = ["", "", ""];
    $data = readTSV($filepath);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Edytowanie TSV z HTML</title>
    <style>
        textarea { width: 100%; height: 200px; padding: 8px; font-size: 20px; resize: vertical; word-wrap: break-word; white-space: normal; }
        button { padding: 10px 15px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table, th, td { border: 1px solid black; }
        th, td { padding: 10px; text-align: left; }
        .html-content { background-color: #f4f4f4; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h2>Podaj pełną ścieżkę pliku TSV i dodaj lub edytuj wpis</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <label>Ścieżka do pliku TSV (katalog/nazwa_pliku):</label>
        <input type="text" name="filepath" value="<?php echo htmlspecialchars($requested); ?>" required>

        <textarea name="column1" placeholder="Kolumna 1" required><?php echo htmlspecialchars($editRow[0]); ?></textarea>
        <textarea name="column2" placeholder="Kolumna 2" required><?php echo htmlspecialchars($editRow[1]); ?></textarea>
        <textarea name="column3" placeholder="Kolumna 3" required><?php echo htmlspecialchars($editRow[2]); ?></textarea>

        <?php if ($editMode): ?>
            <input type="hidden" name="edit_index" value="<?php echo $editIndex; ?>">
            <button type="submit">Zapisz zmiany</button>
        <?php else: ?>
            <button type="submit">Dodaj</button>
        <?php endif; ?>
    </form>

    <h2>Dane z pliku TSV: <?php echo htmlspecialchars($requested); ?></h2>

    <?php if (!empty($data)): ?>
        <table>
            <tr>
                <th>Kolumna 1</th><th>Kolumna 2</th><th>Kolumna 3</th><th>Akcja</th>
            </tr>
            <?php foreach ($data as $index => $row): ?>
                <tr>
                    <!-- HTML wyświetlany celowo jako sformatowany tekst (edytor zaufany, za logowaniem) -->
                    <td class="html-content"><?php echo $row[0]; ?></td>
                    <td class="html-content"><?php echo $row[1]; ?></td>
                    <td class="html-content"><?php echo $row[2]; ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="filepath" value="<?php echo htmlspecialchars($requested); ?>">
                            <input type="hidden" name="edit_index" value="<?php echo $index; ?>">
                            <button type="submit">Edytuj</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>Plik jest pusty.</p>
    <?php endif; ?>
</body>
</html>