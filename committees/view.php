<?php 
// mms/committees/view.php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo   = getPDO();
$user  = currentUser();
$isSuper = isSuperAdmin($user);

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);

// Load committee
$stmt = $pdo->prepare("SELECT * FROM committees WHERE id = :id LIMIT 1");
$stmt->execute(array(':id' => $id));
$committee = $stmt->fetch();

if (!$committee) {
    flash_set('error', 'Committee not found');
    header('Location: /mms/committees/list.php');
    exit;
}

// Load members with designation + participant_type
$membersStmt = $pdo->prepare("
    SELECT 
        cu.*,
        p.full_name,
        p.participant_type,
        d.title AS designation_title
    FROM committee_users cu
    LEFT JOIN participants p ON p.id = cu.participant_id
    LEFT JOIN designations d ON d.id = p.designation_id
    WHERE cu.committee_id = :id
    ORDER BY 
        CASE cu.role_in_committee
            WHEN 'admin' THEN 0
            ELSE 1
        END,
        p.full_name ASC
");
$membersStmt->execute(array(':id' => $id));
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

// Load all designations for dropdown
$designationStmt = $pdo->prepare("
    SELECT id, title 
    FROM designations
    ORDER BY title
");
$designationStmt->execute();
$designations = $designationStmt->fetchAll(PDO::FETCH_ASSOC);

// Classification of designations
$judgeTitles = array(
    "Hon'ble the Chief Justice",
    "Hon'ble the Acting Chief Justice",
    "Hon'ble Mr./Ms. Justice",
    "Hon'ble Administrative Judge"
);

$advocateTitles = array(
    "Advocate",
    "Senior Advocate",
    "Government Advocate, Rajasthan High Court",
    "Additional Advocate General, Rajasthan",
    "Deputy Government Advocate",
    "Public Prosecutor, High Court",
    "Additional Public Prosecutor, High Court"
);

// Only this one will go to manual Gov Officer form
$govOfficerTitles = array(
    "Government Officer"
);
// Everything else (not above) is treated as registry_officer by default

$adminCommittees  = getUserAdminCommitteeIds($pdo, $user);
$canManageMembers = $isSuper || in_array($id, $adminCommittees, true);

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h2 class="mb-1">
      <i class="bi bi-diagram-3-fill text-primary me-2"></i>
      <?= htmlspecialchars($committee['name']) ?>
    </h2>
    <?php if (!empty($committee['description'])): ?>
      <p class="text-muted mb-0 small">
        <?= nl2br(htmlspecialchars($committee['description'])) ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="text-end">
    <div>
      <a class="btn btn-primary btn-sm" href="/mms/meetings/add.php?committee_id=<?= (int)$committee['id'] ?>">
        <i class="bi bi-calendar-plus me-1"></i> Schedule Meeting
      </a>
    </div>
  </div>
</div>

<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash_get('error')): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="row g-3">
  <!-- Members list -->
  <div class="col-lg-7">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="bi bi-people-fill me-1 text-primary"></i>
          Committee Members
        </h5>
        <span class="badge bg-light text-muted">
          <?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?>
        </span>
      </div>
      <div class="card-body p-0">
        <?php if ($members): ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="small text-muted">
                <tr>
                  <th style="width: 40px;">#</th>
                  <th>Name</th>
                  <th>Designation</th>
                  <th style="width: 140px;">Member Type</th>
                  <th style="width: 120px;">Role</th>
                </tr>
              </thead>
              <tbody>
                <?php $i = 1; foreach ($members as $m): ?>
                  <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars(isset($m['full_name']) ? $m['full_name'] : 'Unknown') ?></td>
                    <td>
                      <?php if (!empty($m['designation_title'])): ?>
                        <span class="small"><?= htmlspecialchars($m['designation_title']) ?></span>
                      <?php else: ?>
                        <span class="text-muted small">-</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                        $ptype = isset($m['participant_type']) ? $m['participant_type'] : '';
                        if ($ptype === 'judge') {
                            $ptypeLabel = 'Judge';
                            $ptypeClass = 'bg-primary';
                        } elseif ($ptype === 'registry_officer') {
                            $ptypeLabel = 'Registrar Staff';
                            $ptypeClass = 'bg-info text-dark';
                        } elseif ($ptype === 'advocate') {
                            $ptypeLabel = 'Advocate';
                            $ptypeClass = 'bg-success';
                        } elseif ($ptype === 'gov_officer') {
                            $ptypeLabel = 'Gov. Officer';
                            $ptypeClass = 'bg-warning text-dark';
                        } elseif ($ptype !== '') {
                            $ptypeLabel = ucfirst(str_replace('_', ' ', $ptype));
                            $ptypeClass = 'bg-secondary';
                        } else {
                            $ptypeLabel = 'N/A';
                            $ptypeClass = 'bg-secondary';
                        }
                      ?>
                      <span class="badge <?= $ptypeClass ?> small"><?= htmlspecialchars($ptypeLabel) ?></span>
                    </td>
                    <td>
                      <?php
                        $role = !empty($m['role_in_committee']) ? $m['role_in_committee'] : 'member';
                        $label = ucfirst($role);
                        $badgeClass = ($role === 'admin') ? 'bg-danger' : 'bg-secondary';
                      ?>
                      <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($label) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="p-3">
            <p class="text-muted mb-0">No members added to this committee yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Add member -->
  <div class="col-lg-5">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white border-0">
        <h5 class="mb-0">
          <i class="bi bi-person-plus-fill me-1 text-success"></i>
          Add Member
        </h5>
      </div>
      <div class="card-body">
        <?php if (!$canManageMembers): ?>
          <div class="alert alert-warning small mb-0">
            You don't have permission to add members for this committee.
            Please contact a committee head or the system administrator.
          </div>
        <?php else: ?>

          <form method="post" action="/mms/committees/add_member.php" id="add-member-form" class="row g-2">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="committee_id" value="<?= (int)$committee['id'] ?>">
            <input type="hidden" name="role_in_committee" value="member">

            <!-- Hidden fields for backend -->
            <input type="hidden" name="participant_type" id="participant_type">
            <input type="hidden" name="designation_title" id="designation_title">
            <input type="hidden" name="designation_description" id="designation_description">
            <input type="hidden" name="full_name" id="hidden_full_name">
            <input type="hidden" name="email" id="hidden_email">
            <input type="hidden" name="phone" id="hidden_phone">
            <input type="hidden" name="external_source" id="external_source">
            <input type="hidden" name="external_id" id="external_id">

            <!-- Step 1: Select Designation -->
            <div class="col-12">
              <label class="form-label small text-muted mb-1">Designation</label>
              <select id="designation_select" class="form-select form-select-sm" required>
                <option value="">-- Select Designation --</option>
                <?php foreach ($designations as $d): 
                    $title = $d['title'];
                    $category = 'registry_officer'; // default

                    if (in_array($title, $judgeTitles, true)) {
                        $category = 'judge';
                    } elseif (in_array($title, $advocateTitles, true)) {
                        $category = 'advocate';
                    } elseif (in_array($title, $govOfficerTitles, true)) {
                        $category = 'gov_officer';
                    }
                ?>
                  <option
                    value="<?= (int)$d['id'] ?>"
                    data-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                    data-category="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"
                  >
                    <?= htmlspecialchars($title) ?>
                  </option>
                <?php endforeach; ?>

                <!-- Special option to add a completely new designation -->
                <option value="ADD_NEW" data-category="gov_officer">
                  + Add new designation...
                </option>
              </select>
              <div class="form-text small">
                Judges (CJ / ACJ / Justice / Administrative Judge) use Judges API,
                registry staff use Registry API, Advocates & Government Officers use manual forms.
              </div>
            </div>

            <!-- Section: API-based selection (Judges / Registry) -->
            <div id="api-member-section" class="col-12" style="display:none;">
              <div class="border rounded p-2 mb-2">
                <label class="form-label small text-muted mb-1">Search &amp; Select Person (from API)</label>
                <div class="input-group input-group-sm mb-2">
                  <input type="text" id="api_search_query" class="form-control" placeholder="Type name / ID to search">
                  <button class="btn btn-outline-secondary" type="button" id="api_search_button">
                    <i class="bi bi-search"></i>
                  </button>
                </div>
                <select id="api_result_select" class="form-select form-select-sm mb-2">
                  <option value="">-- Search above and select a person --</option>
                </select>
                <div class="form-text small">
                  For Judges (CJ/ACJ/Justice/Admin Judge) data comes from Judges API.
                  For registry designations data comes from Registry API.
                </div>
              </div>
            </div>

            <!-- Section: Advocate manual form -->
            <div id="advocate-section" class="col-12" style="display:none;">
              <div class="border rounded p-2 mb-2">
                <p class="small text-muted mb-2">
                  Enter Advocate details.
                </p>
                <div class="mb-2">
                  <label class="form-label small text-muted mb-1">Full Name</label>
                  <input type="text" id="adv_full_name" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                  <label class="form-label small text-muted mb-1">Designation</label>
                  <input type="text" id="adv_designation" class="form-control form-control-sm" value="">
                </div>
                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label small text-muted mb-1">Mobile No.</label>
                    <input type="text" id="adv_mobile" class="form-control form-control-sm">
                  </div>
                  <div class="col-6">
                    <label class="form-label small text-muted mb-1">Email (optional)</label>
                    <input type="email" id="adv_email" class="form-control form-control-sm">
                  </div>
                </div>
              </div>
            </div>

            <!-- Section: Government Officer / New Designation manual form -->
            <div id="gov-officer-section" class="col-12" style="display:none;">
              <div class="border rounded p-2 mb-2">
                <p class="small text-muted mb-2" id="gov_form_caption">
                  Enter details of the Government Officer.
                </p>
                <div class="mb-2">
                  <label class="form-label small text-muted mb-1">Full Name</label>
                  <input type="text" id="gov_full_name" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                  <label class="form-label small text-muted mb-1">Designation (exact)</label>
                  <input type="text" id="gov_designation" class="form-control form-control-sm" placeholder="e.g. Joint Secretary, Law Department">
                </div>
                <div class="mb-2">
                  <label class="form-label small text-muted mb-1">Department</label>
                  <input type="text" id="gov_department" class="form-control form-control-sm">
                </div>
                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label small text-muted mb-1">Mobile No.</label>
                    <input type="text" id="gov_mobile" class="form-control form-control-sm">
                  </div>
                  <div class="col-6">
                    <label class="form-label small text-muted mb-1">Email (optional)</label>
                    <input type="email" id="gov_email" class="form-control form-control-sm">
                  </div>
                </div>
                <div class="form-text small">
                  Department is stored in the designation description for new designations.
                </div>
              </div>
            </div>

            <div class="col-12 mt-1">
              <button type="submit" class="btn btn-success btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Add Member
              </button>
            </div>
          </form>

        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var designationSelect       = document.getElementById('designation_select');

    var apiSection              = document.getElementById('api-member-section');
    var advSection              = document.getElementById('advocate-section');
    var govSection              = document.getElementById('gov-officer-section');

    var govFormCaption          = document.getElementById('gov_form_caption');

    var participantTypeInput    = document.getElementById('participant_type');
    var designationTitleInput   = document.getElementById('designation_title');
    var designationDescInput    = document.getElementById('designation_description');
    var externalSourceInput     = document.getElementById('external_source');
    var externalIdInput         = document.getElementById('external_id');
    var hiddenFullName          = document.getElementById('hidden_full_name');
    var hiddenEmail             = document.getElementById('hidden_email');
    var hiddenPhone             = document.getElementById('hidden_phone');

    var apiSearchButton         = document.getElementById('api_search_button');
    var apiSearchQuery          = document.getElementById('api_search_query');
    var apiResultSelect         = document.getElementById('api_result_select');

    var advFullName             = document.getElementById('adv_full_name');
    var advDesignation          = document.getElementById('adv_designation');
    var advMobile               = document.getElementById('adv_mobile');
    var advEmail                = document.getElementById('adv_email');

    var govFullName             = document.getElementById('gov_full_name');
    var govDesignation          = document.getElementById('gov_designation');
    var govDepartment           = document.getElementById('gov_department');
    var govMobile               = document.getElementById('gov_mobile');
    var govEmail                = document.getElementById('gov_email');

    var addMemberForm           = document.getElementById('add-member-form');

    var currentApiUrl = null;

    function resetHiddenFields() {
        externalIdInput.value       = '';
        hiddenFullName.value        = '';
        hiddenEmail.value           = '';
        hiddenPhone.value           = '';
        designationTitleInput.value = '';
        designationDescInput.value  = '';
    }

    function hideAllSections() {
        if (apiSection) apiSection.style.display = 'none';
        if (advSection) advSection.style.display = 'none';
        if (govSection) govSection.style.display = 'none';
    }

    // When designation changes, decide which section to show
    if (designationSelect) {
        designationSelect.addEventListener('change', function() {
            var value     = designationSelect.value;
            var option    = designationSelect.options[designationSelect.selectedIndex];
            var title     = option ? (option.getAttribute('data-title') || option.textContent || '') : '';
            var category  = option ? (option.getAttribute('data-category') || '') : '';

            resetHiddenFields();
            hideAllSections();

            if (!value) {
                participantTypeInput.value = '';
                externalSourceInput.value  = '';
                currentApiUrl = null;
                return;
            }

            // Add new designation path
            if (value === 'ADD_NEW') {
                participantTypeInput.value = 'gov_officer';
                externalSourceInput.value  = 'MANUAL';
                if (govSection) govSection.style.display = 'block';
                if (govFormCaption) govFormCaption.textContent = 'Add new designation and person details.';
                if (govDesignation) govDesignation.value = '';
                if (govDepartment)  govDepartment.value  = '';
                if (govFullName)    govFullName.value    = '';
                if (govMobile)      govMobile.value      = '';
                if (govEmail)       govEmail.value       = '';
                return;
            }

            // Existing designations
            if (!category) {
                participantTypeInput.value = '';
                externalSourceInput.value  = '';
                currentApiUrl = null;
                return;
            }

            if (category === 'judge') {
                participantTypeInput.value = 'judge';
                externalSourceInput.value  = 'JUDGES_API';
                currentApiUrl              = '/mms/api/judges_search.php'; // your future judges API
                if (apiSection) apiSection.style.display = 'block';
                designationTitleInput.value = title; // e.g. Hon'ble Mr./Ms. Justice
            } else if (category === 'registry_officer') {
                participantTypeInput.value = 'registry_officer';
                externalSourceInput.value  = 'REGISTRY_API';

                // use same SSO user search you already have
                currentApiUrl              = '../admin/searchUsers.php';

                if (apiSection) apiSection.style.display = 'block';
                designationTitleInput.value = title; // Registrar / Deputy Registrar (Judicial) / etc.
            } else if (category === 'advocate') {
                participantTypeInput.value = 'advocate';
                externalSourceInput.value  = 'MANUAL';
                if (advSection) advSection.style.display = 'block';
                if (advDesignation) advDesignation.value = title; // Advocate / Sr Adv / etc.
                designationTitleInput.value = title;
            } else if (category === 'gov_officer') {
                participantTypeInput.value = 'gov_officer';
                externalSourceInput.value  = 'MANUAL';
                if (govSection) govSection.style.display = 'block';
                if (govFormCaption) govFormCaption.textContent = 'Enter details of the Government Officer.';
                if (govDesignation) govDesignation.value = '';
                if (govDepartment)  govDepartment.value  = '';
                if (govFullName)    govFullName.value    = '';
                if (govMobile)      govMobile.value      = '';
                if (govEmail)       govEmail.value       = '';
            } else {
                // default safety: treat as registry_officer
                participantTypeInput.value = 'registry_officer';
                externalSourceInput.value  = 'REGISTRY_API';
                currentApiUrl              = '../admin/searchUsers.php';
                if (apiSection) apiSection.style.display = 'block';
                designationTitleInput.value = title;
            }
        });
    }

    // --- API search click (Judges / Registry via AJAX) ---
    if (apiSearchButton && apiSearchQuery && apiResultSelect) {
        apiSearchButton.addEventListener('click', function() {
            if (!currentApiUrl) {
                alert('No API configured for this designation.');
                return;
            }
            var q = apiSearchQuery.value.trim();
            if (!q || q.length < 2) {
                alert('Please enter at least 2 characters to search.');
                return;
            }

            var url = currentApiUrl + '?q=' + encodeURIComponent(q);

            // Debug if needed:
            // console.log('Calling API:', url);

            fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                var normalized;

                if (!Array.isArray(data)) {
                    console.error('Response is not an array:', data);
                    return;
                }

                if (currentApiUrl.indexOf('searchUsers.php') !== -1) {
                    // Normalize SSO user search to generic structure
                    normalized = [];
                    for (var i = 0; i < data.length; i++) {
                        var u = data[i];
                        normalized.push({
                            id:          u.rjcode,                // external_id
                            name:        u.display_name || '',    // full name
                            designation: designationTitleInput.value || '',
                            email:       u.email || '',
                            phone:       '',
                            department:  ''
                        });
                    }
                } else {
                    // For future judges API that already returns generic objects
                    normalized = data;
                }

                fillApiResults(normalized);
            })
            .catch(function (error) {
                console.error('API search error:', error);
                alert('Error while searching. Please try again.');
            });
        });
    }

    function fillApiResults(data) {
        apiResultSelect.innerHTML = '';

        var defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.text  = '-- Select a person --';
        apiResultSelect.appendChild(defaultOpt);

        for (var i = 0; i < data.length; i++) {
            var item = data[i];
            var opt  = document.createElement('option');
            opt.value = item.id; // external_id from API

            var label = item.name || 'Unknown';
            if (item.designation) {
                label += ' (' + item.designation + ')';
            }
            opt.text  = label;

            if (item.name)       opt.setAttribute('data-name', item.name);
            if (item.email)      opt.setAttribute('data-email', item.email);
            if (item.phone)      opt.setAttribute('data-phone', item.phone);
            if (item.designation)opt.setAttribute('data-designation', item.designation);
            if (item.department) opt.setAttribute('data-department', item.department);

            apiResultSelect.appendChild(opt);
        }
    }

    // When user selects an entry from API results, fill hidden fields
    if (apiResultSelect) {
        apiResultSelect.addEventListener('change', function() {
            var selectedValue = apiResultSelect.value;
            if (!selectedValue) {
                externalIdInput.value      = '';
                hiddenFullName.value       = '';
                hiddenEmail.value          = '';
                hiddenPhone.value          = '';
                designationDescInput.value = '';
                return;
            }

            var selectedOption = apiResultSelect.options[apiResultSelect.selectedIndex];

            var name        = selectedOption.getAttribute('data-name') || '';
            var email       = selectedOption.getAttribute('data-email') || '';
            var phone       = selectedOption.getAttribute('data-phone') || '';
            var desig       = selectedOption.getAttribute('data-designation') || '';
            var department  = selectedOption.getAttribute('data-department') || '';

            externalIdInput.value    = selectedValue;
            hiddenFullName.value     = name;
            hiddenEmail.value        = email;
            hiddenPhone.value        = phone;

            if (desig !== '') {
                designationTitleInput.value = desig;
            }

            if (department !== '') {
                designationDescInput.value = 'Department: ' + department;
            } else {
                designationDescInput.value = '';
            }
        });
    }

    // Submit validation
    if (addMemberForm) {
        addMemberForm.addEventListener('submit', function(e) {
            var ptype = participantTypeInput.value;

            if (!ptype) {
                alert('Please select a designation first.');
                e.preventDefault();
                return;
            }

            // Judges / Registry via API
            if (ptype === 'judge' || ptype === 'registry_officer') {
                if (!externalIdInput.value) {
                    alert('Please search and select a person from the API list.');
                    e.preventDefault();
                    return;
                }
                if (!hiddenFullName.value) {
                    alert('Full name from API is missing. Integrate the API mapping first.');
                    e.preventDefault();
                    return;
                }
                if (!designationTitleInput.value) {
                    designationTitleInput.value = (ptype === 'judge')
                        ? "Hon'ble Mr./Ms. Justice"
                        : 'Registrar';
                }
                return;
            }

            // Advocate manual
            if (ptype === 'advocate') {
                var name   = advFullName.value.trim();
                var desig  = advDesignation.value.trim();
                var mobile = advMobile.value.trim();
                var email  = advEmail.value.trim();

                if (!name || !mobile) {
                    alert('Please fill Name and Mobile for Advocate.');
                    e.preventDefault();
                    return;
                }

                hiddenFullName.value        = name;
                hiddenPhone.value           = mobile;
                hiddenEmail.value           = email;
                designationTitleInput.value = desig !== '' ? desig : 'Advocate';
                designationDescInput.value  = '';
                externalIdInput.value       = '';
                externalSourceInput.value   = 'MANUAL';
                return;
            }

            // Government Officer or Add New Designation (both use gov form)
            if (ptype === 'gov_officer') {
                var gname   = govFullName.value.trim();
                var gdesig  = govDesignation.value.trim();
                var gdept   = govDepartment.value.trim();
                var gmobile = govMobile.value.trim();
                var gemail  = govEmail.value.trim();

                if (!gname || !gdesig || !gmobile) {
                    alert('Please fill Name, Designation and Mobile.');
                    e.preventDefault();
                    return;
                }

                hiddenFullName.value        = gname;
                hiddenPhone.value           = gmobile;
                hiddenEmail.value           = gemail;
                designationTitleInput.value = gdesig;
                designationDescInput.value  = gdept !== '' ? ('Department: ' + gdept) : '';
                externalIdInput.value       = '';
                externalSourceInput.value   = 'MANUAL';
                return;
            }
        });
    }

    // For possible future direct calls
    window.mmsFillApiResults = fillApiResults;
});
</script>


