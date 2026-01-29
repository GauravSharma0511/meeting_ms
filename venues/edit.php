<?php
// mms/venues/edit.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo = getPDO();
$user = $_SESSION['user'] ?? null;

// Read ID from query
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash_set('error', 'Invalid venue ID.');
    header('Location: list.php');
    exit;
}

// Fetch existing venue row with all columns
$stmt = $pdo->prepare("SELECT * FROM venues WHERE id = :id");
$stmt->execute([':id' => $id]);
$venue = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venue) {
    flash_set('error', 'Venue not found.');
    header('Location: list.php');
    exit;
}

// We'll treat every column except "id" as editable
$originalVenue = $venue;
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // All editable fields sent in a single "fields" array
    $fields = $_POST['fields'] ?? [];

    // Update our in-memory row with new values
    foreach ($venue as $col => $val) {
        if ($col === 'id') {
            continue;
        }
        if (array_key_exists($col, $fields)) {
            // Trim strings; leave others as-is
            $venue[$col] = is_string($fields[$col]) ? trim($fields[$col]) : $fields[$col];
        }
    }

    // Example validation: if there is a "name" column, ensure it's not empty
    if (array_key_exists('name', $venue) && $venue['name'] === '') {
        $errors[] = 'Venue name is required.';
    }

    if (!$errors) {
        // Build dynamic UPDATE query
        $setParts = [];
        $params   = [':id' => $id];

        foreach ($venue as $col => $val) {
            if ($col === 'id') {
                continue;
            }
            $setParts[]        = "\"$col\" = :$col"; // quotes for Postgres safety
            $params[":$col"]   = $val === '' ? null : $val;
        }

        if ($setParts) {
            $sql = "UPDATE venues SET " . implode(', ', $setParts) . " WHERE id = :id";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);

            flash_set('success', 'Venue updated successfully.');
        }

        header('Location: list.php');
        exit;
    }
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Edit Venue</h3>
  <a href="list.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="card card-body">
  <?php foreach ($venue as $col => $val): ?>
    <?php if ($col === 'id') continue; ?>

    <?php
      // Label: convert "room_no" -> "Room no"
      $label = ucwords(str_replace('_', ' ', $col));
    ?>

    <div class="mb-3">
      <label class="form-label">
        <?= htmlspecialchars($label) ?>
        <?php if ($col === 'name'): ?>
          <span class="text-danger">*</span>
        <?php endif; ?>
      </label>

      <input type="text"
             name="fields[<?= htmlspecialchars($col) ?>]"
             class="form-control"
             value="<?= htmlspecialchars((string)$val) ?>">
    </div>
  <?php endforeach; ?>

  <button type="submit" class="btn btn-success">
    Save Changes
  </button>
</form>

<?php include __DIR__ . '/../footer.php'; ?>
