function initCommitteeModule(config) {
    // ALL existing JS logic goes here
    const members = [];

    const addAs = document.getElementById('add_as');
    const memberType = document.getElementById('member_type');
    const memberTypeBox = document.getElementById('memberTypeBox');

    const searchBox = document.getElementById('searchBox');
    const manualBox = document.getElementById('manualBox');
    const deptBox = document.getElementById('deptBox');

    const searchInput = document.getElementById('search_query');
    const searchResult = document.getElementById('search_results');

    const addBtn = document.getElementById('addPersonBtn');
    const previewList = document.getElementById('previewList');

    const designation = document.getElementById('designation_id');

    const mName = document.getElementById('manual_name');
    const mPhone = document.getElementById('manual_phone');
    const mEmail = document.getElementById('manual_email');
    const mDept = document.getElementById('manual_department');

    const Newform = document.getElementById('committeeForm');
    if (!addAs || !memberType || !Newform) {
        console.error('Committee modal elements not found');
        return;
    }

    let currentApi = null;

            //MEMBER ERROR CONDITIONTION
            const memberErrorBox = document.getElementById('memberError');

        function showMemberError(msg) {
            if (!memberErrorBox) return;
            memberErrorBox.textContent = msg;
            memberErrorBox.style.display = 'block';
        }

        function clearMemberError() {
            if (!memberErrorBox) return;
            memberErrorBox.textContent = '';
            memberErrorBox.style.display = 'none';
        }

        // basic checks for forms 
            function hasOnlyZeros(val) {
            return /^0+$/.test(val);
        }

        function hasRepeatedTrailing(val, limit = 4) {
            return /(.)\1{4,}$/.test(val);
        }

        function isValidCommitteeName(name) {
            return /^[A-Za-z0-9 ()_-]+$/.test(name);
        }

        function isValidMobile(mobile) {
            if (!/^\d{10}$/.test(mobile)) return false;
            return !/^(\d)\1{9}$/.test(mobile);
        }

        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
        }


    // 🔐 Registry Admin State (ONLY ADMIN SOURCE

    /* ---------- ADD AS ---------- */
    addAs.addEventListener('change', function () {
        memberType.value = '';
        memberTypeBox.classList.remove('d-none');
        searchBox.classList.add('d-none');
        manualBox.classList.add('d-none');
        deptBox.classList.add('d-none');
    });

    /* ---------- MEMBER TYPE ---------- */
    memberType.addEventListener('change', function () {

        searchInput.value = '';
        searchResult.innerHTML = '';

        searchBox.classList.add('d-none');
        manualBox.classList.add('d-none');
        deptBox.classList.add('d-none');


        // 🔐 ADMIN vs MEMBER BEHAVIOUR
    if (addAs.value === 'admin') {
        addBtn.disabled = false;
        previewList.innerHTML =
            '<li class="list-group-item text-info">Nodal Officer and Chairperson Should be Selected Only from Registry Officers.</li>';
    } else {
        addBtn.disabled = false;
        previewList.innerHTML = '';
    }

        if (memberType.value === 'judge') {
            currentApi = '/mms/admin/judges_Search.php';
            searchBox.classList.remove('d-none');
        }
        else if (memberType.value === 'registry') {
            currentApi = '/mms/admin/searchUsers.php';
            searchBox.classList.remove('d-none');
        }
        else if (memberType.value === 'advocate') {
            manualBox.classList.remove('d-none');
        }
        else if (memberType.value === 'govt') {
            manualBox.classList.remove('d-none');
            deptBox.classList.remove('d-none');
        }
    });

/* ---------- SEARCH ---------- */
/* ---------- SEARCH ---------- */
    searchInput.addEventListener('keyup', function () {
    if (searchInput.value.length < 2 || !currentApi) return;

    fetch(currentApi + '?q=' + encodeURIComponent(searchInput.value))
        .then(r => r.json())
        .then(data => {
            searchResult.innerHTML = '<option value="">Select</option>';

            data.forEach(item => {
                const opt = document.createElement('option');

                // ID (judge = jocode, registry = rjcode)
                opt.value = item.jocode || item.rjcode || '';

                // ---------- NAME (SALUTE + NAME) ----------
                if (item.judge_name) {
                    let fullName = '';

                    if (item.salute) {
                        fullName += item.salute.trim() + ' ';
                        opt.dataset.salute = item.salute.trim(); // ✅ STORE SAFELY
                    }

                    fullName += (item.judge_name || '').trim();
                    opt.textContent = fullName;
                } else {
                    // registry / others
                    opt.textContent = (item.display_name || '').trim();
                }

                // metadata
                opt.dataset.email = item.email || '';
                opt.dataset.judgeName = item.judge_name || '';

                searchResult.appendChild(opt);
            });
        });
  });




   

    addBtn.addEventListener('click', function () {

    if (!addAs.value || !memberType.value || !designation.value) {
        alert('Add As, Member Type & Designation required');
        return;
    }

    // 🔒 ADMIN RULE: admin MUST be from registry
    if (addAs.value === 'admin' && memberType.value !== 'registry') {
        alert('Only Registry users can be added as Admin');
        return;
    }

    let entry = {
        add_as: addAs.value,
        participant_type: memberType.value,
        designation_id: designation.value
    };

    if (!searchBox.classList.contains('d-none')) {
    // ================= API (Judge / Registry) =================
    const opt = searchResult.selectedOptions[0];
    if (!opt || !opt.value) {
        alert('Select a person');
        return;
    }

    entry.full_name = opt.textContent;
    entry.external_id = opt.value; // RJ CODE
    entry.email = opt.dataset.email || null;
    entry.external_source = 'API';

} else {
    // ================= MANUAL (Advocate / Govt) =================
    if (!mName.value || !mPhone.value) {
        alert('Name & phone required');
        return;
    }

    // 🔒 MOBILE VALIDATION
    if (!isValidMobile(mPhone.value)) {
        showMemberError('Invalid mobile number');
        return;
    }

    // 🔒 EMAIL VALIDATION
    if (mEmail.value && !isValidEmail(mEmail.value)) {
        showMemberError('Invalid email format');
        return;
    }

    // 🔒 GOVT DEPARTMENT VALIDATION
    if (!deptBox.classList.contains('d-none')) {
        if (!mDept.value) {
            showMemberError('Department is required');
            return;
        }
        if (hasOnlyZeros(mDept.value) || hasRepeatedTrailing(mDept.value)) {
            showMemberError('Invalid department name');
            return;
        }
        entry.department = mDept.value.trim();
    }

    entry.full_name = mName.value.trim();
    entry.phone = mPhone.value;
    entry.email = mEmail.value ? mEmail.value.trim().toLowerCase() : null;
    entry.external_source = 'MANUAL';
}

clearMemberError();
/* 🔁 DUPLICATE MEMBER CHECK (COMMON FOR BOTH) */
const exists = members.some(m => {

    // API based members (judge / registry)
    if (entry.external_source === 'API' && m.external_source === 'API') {
        return (
            m.external_id === entry.external_id &&
            String(m.designation_id) === String(entry.designation_id)
        );
    }

    // MANUAL based members (advocate / govt)
    if (entry.external_source === 'MANUAL' && m.external_source === 'MANUAL') {
        return (
            m.full_name.trim().toLowerCase() === entry.full_name.trim().toLowerCase() &&
            String(m.designation_id) === String(entry.designation_id) &&
            m.phone === entry.phone
        );
    }
    // API members → unique by external_id
    if (entry.external_source === 'API' && m.external_source === 'API') {
        return m.external_id === entry.external_id;
    }

      // MANUAL members → mobile must be unique
    if (entry.external_source === 'MANUAL' && m.external_source === 'MANUAL') {
        return m.phone === entry.phone;
    }


    return false;
});

if (exists) {
    showMemberError('This member with same name, designation and mobile already exists');
    return;
}

clearMemberError();

members.push(entry);


    const li = document.createElement('li');
    li.className = 'list-group-item';
    li.textContent = `${entry.full_name} (${entry.add_as})`;
    previewList.appendChild(li);

    alert('Added successfully');
});

    /* ---------- SUBMIT COMMITTEE ---------- */
    Newform.addEventListener('submit', function (e) {

        // e.preventDefault();
        // console.log('Committee form submitted');

        

        if (members.length === 0) {
            showMemberError('Please add at least one member');
            return;
        }

       const committeeName = document.getElementById('committee_name').value.trim();
       const description   = document.getElementById('committee_description').value.trim();




        if (!committeeName || !description) {
            showMemberError('Committee name and description are required');
            return;
        }
            if (!isValidCommitteeName(committeeName)) {
            alert('Committee name allows only letters, numbers, space, (), -, _');
            return;
        }

        if (hasOnlyZeros(committeeName)) {
            showMemberError('Committee name cannot be all zeros');
            return;
        }

        if (hasRepeatedTrailing(committeeName)) {
            showMemberError('Committee name has invalid repeated characters');
            return;
        }

        if (hasOnlyZeros(description)) {
            alert('Description cannot be all zeros');
            return;
        }

        if (hasRepeatedTrailing(description)) {
            alert('Description has invalid repeated characters');
            return;
        }

        const payload = {
            name: committeeName,
            description: description,

            members: members
        };

        console.log('Payload:', payload);

        fetch('/mms/ajax/create_committee.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf: document.getElementById('csrf').value,
                payload: JSON.stringify(payload)
            })
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                alert(res.error || 'Failed');
                return;
            }

            alert('Committee created successfully');
            location.reload();
        })
        .catch(err => {
            console.error(err);
            alert('Server error');
        });
    });
}