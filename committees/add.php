<?php
// mms/committees/add.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();
$user  = $_SESSION['user'] ?? null;
$role  = $user['role'] ?? '';
$isSuper = ($role === 'superuser');

if (!$isSuper) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$pdo = getPDO();

/* ---------- LOAD DESIGNATIONS (REQUIRED) ---------- */
$designations = [];

$errors = [];
try {
    $designations = $pdo->query("SELECT id, title FROM designations ORDER BY title ASC")
                        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $designations = [];
}



include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">
    <i class="bi bi-diagram-3-fill text-primary me-1"></i>
    Add Committee
  </h3>
  <a href="list.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left-short"></i> Back to list
  </a>
</div>
<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  
<?php endif; ?>
<div class="container-fluid">
   <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11 mx-auto">

            <div class="card shadow-sm border-0">
              

                <div class="card-body pt-4">

                    <form id="committeeForm" autocomplete="off">

                        <!-- CSRF -->
                        <input type="hidden" id="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

                        <!-- ================= COMMITTEE INFO ================= -->
                        <h5 class="mb-3 text-secondary">Committee Details</h5>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Committee Name <span class="text-danger">*</span></label>
                                <input type="text"
                                       id="committee_name"
                                       class="form-control"
                                       placeholder="Enter committee name">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <textarea id="committee_description"
                                          class="form-control"
                                          rows="2"
                                          placeholder="Brief description of committee"></textarea>
                            </div>
                        </div>

                        <hr>

                        <!-- ================= MEMBER ADDING ================= -->
                        <h5 class="mb-3 text-secondary">Add Committee Members</h5>

                        <!-- ADD AS + MEMBER TYPE -->
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Add As <span class="text-danger">*</span></label>
                                <select id="add_as" class="form-select">
                                    <option value="">Select</option>
                                    <option value="admin">Admin</option>
                                    <option value="member">Member</option>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3" id="memberTypeBox">
                                <label class="form-label">Member Type <span class="text-danger">*</span></label>
                                <select id="member_type" class="form-select">
                                    <option value="">Select</option>
                                    <option value="judge">Judge</option>
                                    <option value="registry">Registry Officer</option>
                                    <option value="advocate">Advocate</option>
                                    <option value="govt">Government Officer</option>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Designation <span class="text-danger">*</span></label>
                                <select id="designation_id" class="form-select">
                                    <option value="">Select</option>
                                    <?php foreach ($designations as $d): ?>
                                        <option value="<?= $d['id'] ?>">
                                            <?= htmlspecialchars($d['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- ================= SEARCH BOX ================= -->
                        <div class="row d-none" id="searchBox">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Search</label>
                                <input type="text"
                                       id="search_query"
                                       class="form-control"
                                       placeholder="Type at least 2 characters">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Results</label>
                                <select id="search_results" class="form-select"></select>
                            </div>
                        </div>

                        <!-- ================= MANUAL ENTRY ================= -->
                        <div class="row d-none" id="manualBox">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" id="manual_name" class="form-control">
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mobile <span class="text-danger">*</span></label>
                                <input type="text" id="manual_phone" class="form-control">
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" id="manual_email" class="form-control">
                            </div>
                        </div>

                        <!-- ================= GOVT DEPARTMENT ================= -->
                        <div class="row d-none" id="deptBox">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department <span class="text-danger">*</span></label>
                                <input type="text"
                                       id="manual_department"
                                       class="form-control">
                            </div>
                        </div>

                        <!-- ERROR BOX -->
                        <div id="memberError"
                             class="alert alert-danger py-2 d-none"></div>

                        <!-- ADD MEMBER BUTTON -->
                        <div class="mb-3">
                            <button type="button"
                                    id="addPersonBtn"
                                    class="btn btn-outline-primary">
                                + Add Member
                            </button>
                        </div>

                        <!-- PREVIEW -->
                        <ul id="previewList" class="list-group mb-4"></ul>

                        <hr>

                        <!-- SUBMIT -->
                        <div class="text-end">
                            <button type="submit"
                                    class="btn btn-success px-4">
                                Create Committee
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>
</div>






<!-- JS: Member search/add + Admin search filter -->

<script src="/mms/assets/js/committee-core.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    initCommitteeModule({
        formId: 'committeeForm',
        addAsId: 'add_as',
        memberTypeId: 'member_type',
        designationId: 'designation_id',
        searchInputId: 'search_query',
        searchResultId: 'search_results',
        addBtnId: 'addPersonBtn',
        previewListId: 'previewList'
    });
});
</script>


<?php include __DIR__ . '/../footer.php'; ?>
