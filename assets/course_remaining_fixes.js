(function () {
    const path = window.location.pathname;
    if (!path.includes('/faculty/course_edit.php')) return;

    const params = new URLSearchParams(window.location.search);
    const step = params.get('step') || '1';
    const courseId = params.get('id') || '';

    const style = document.createElement('style');
    style.textContent = `
        .other-box { display: none; margin-top: 8px; }
        .other-box.show { display: block !important; }
        .clo-link-warning { display: block; color: #b45309; font-size: 12px; line-height: 1.4; }
        .aqms-inline-note { margin: 10px 0 14px; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff7ed; color: #7c2d12; font-size: 13px; }
    `;
    document.head.appendChild(style);

    window.toggleOther = function (select) {
        const box = select.parentElement.querySelector('.other-box');
        if (!box) return;
        if (select.value === 'Other') {
            box.classList.add('show');
            box.style.display = 'block';
        } else {
            box.classList.remove('show');
            box.style.display = 'none';
            box.value = '';
        }
    };

    function syncAllOtherBoxes(root) {
        (root || document).querySelectorAll('select').forEach(select => {
            const box = select.parentElement ? select.parentElement.querySelector('.other-box') : null;
            if (!box) return;
            box.placeholder = 'Please specify';
            window.toggleOther(select);
            if (!select.dataset.otherBound) {
                select.dataset.otherBound = '1';
                select.addEventListener('change', function () { window.toggleOther(this); });
            }
        });
    }

    function makeSelectFromInput(input, options, placeholder, otherPlaceholder) {
        if (!input || input.dataset.selectFixed === '1') return;
        input.dataset.selectFixed = '1';
        const oldValue = input.value || '';
        const wrapper = document.createElement('div');
        const select = document.createElement('select');
        const hidden = document.createElement('input');
        const other = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = input.name;
        other.type = 'text';
        other.placeholder = otherPlaceholder || 'Please specify';
        other.className = 'other-box';
        other.style.display = 'none';
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder || '-- Select --';
        select.appendChild(empty);
        options.forEach(opt => {
            const option = document.createElement('option');
            option.value = opt;
            option.textContent = opt;
            select.appendChild(option);
        });
        if (options.includes(oldValue)) {
            select.value = oldValue;
            hidden.value = oldValue;
        } else if (oldValue.trim() !== '') {
            select.value = 'Other';
            other.value = oldValue;
            other.classList.add('show');
            other.style.display = 'block';
            hidden.value = oldValue;
        }
        function sync() {
            if (select.value === 'Other') {
                other.classList.add('show');
                other.style.display = 'block';
                hidden.value = other.value.trim();
            } else {
                other.classList.remove('show');
                other.style.display = 'none';
                hidden.value = select.value;
            }
        }
        select.addEventListener('change', sync);
        other.addEventListener('input', sync);
        wrapper.appendChild(select);
        wrapper.appendChild(other);
        wrapper.appendChild(hidden);
        input.replaceWith(wrapper);
        sync();
    }

    function fixNumberedTable(table, inputSelector, totalClass, totalSuffix) {
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        function updateTotal() {
            let total = 0;
            table.querySelectorAll(inputSelector).forEach(input => total += parseFloat(input.value) || 0);
            let totalRow = table.querySelector('.' + totalClass + '-row');
            if (!totalRow) {
                const columnCount = table.querySelectorAll('thead th').length || 3;
                totalRow = document.createElement('tr');
                totalRow.className = totalClass + '-row';
                totalRow.innerHTML = '<td colspan="' + Math.max(columnCount - 2, 1) + '"><strong>Total</strong></td><td><strong class="' + totalClass + '-value">0</strong></td><td></td>';
                tbody.appendChild(totalRow);
            }
            totalRow.querySelector('.' + totalClass + '-value').textContent = total + (totalSuffix || '');
        }
        function renumber() {
            Array.from(tbody.querySelectorAll('tr:not(.' + totalClass + '-row)')).forEach((row, index) => {
                const first = row.querySelector('td');
                if (first) first.textContent = (index + 1) + '.';
            });
            updateTotal();
        }
        table.addEventListener('input', updateTotal);
        table.addEventListener('click', function () { setTimeout(renumber, 0); });
        renumber();
    }

    function fixEmptyLinkedCloCells() {
        const table = document.getElementById('assess-table');
        if (!table) return;
        const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim().toLowerCase());
        let index = headers.findIndex(text => text.includes('linked clo'));
        if (index < 0) index = 3;
        table.querySelectorAll('tbody tr:not(.assessment-total-row)').forEach(row => {
            const cell = row.children[index];
            if (!cell) return;
            const hasCloInput = cell.querySelector('input[type="checkbox"]');
            const hasText = cell.textContent.trim().length > 0;
            if (!hasCloInput && !hasText) {
                cell.innerHTML = '<span class="clo-link-warning">Save Section B CLOs first, then return here to link assessment activities.</span>';
            }
        });
    }

    if (step === '1') {
        const hourInputs = Array.from(document.querySelectorAll('input[name^="hours"][name$="[hours]"]'));
        hourInputs.forEach(input => input.addEventListener('input', function () {
            let total = 0;
            hourInputs.forEach(x => total += parseFloat(x.value) || 0);
            const totalCell = Array.from(document.querySelectorAll('td strong')).find(x => x.textContent.trim() === 'Total');
            if (totalCell && totalCell.closest('tr')) {
                const last = totalCell.closest('tr').querySelector('td:last-child strong');
                if (last) last.textContent = total;
            }
        }));
    }

    if (step === '3') {
        fixNumberedTable(document.getElementById('topic-table'), 'input[name*="[contact_hours]"]', 'topic-total', '');
    }

    if (step === '4') {
        const table = document.getElementById('assess-table');
        if (!table) return;
        const card = table.closest('.card');
        if (card && courseId) {
            const note = document.createElement('div');
            note.className = 'aqms-inline-note';
            note.innerHTML = 'After saving assessment activities, open <a href="assessment_details.php?id=' + encodeURIComponent(courseId) + '">Assessment Rubrics and Performance Tasks</a> to complete the rubric/performance-task requirement.';
            table.parentElement.insertBefore(note, table);
        }
        const options = ['Written test', 'Oral test', 'Oral presentation', 'Group project', 'Essay', 'Other'];
        table.querySelectorAll('input[name*="[activity_name]"]').forEach(input => {
            makeSelectFromInput(input, options, '-- Select assessment activity --', 'Please specify');
        });
        const oldAddAssessRow = window.addAssessRow;
        window.addAssessRow = function () {
            if (typeof oldAddAssessRow === 'function') oldAddAssessRow();
            setTimeout(function () {
                syncAllOtherBoxes(table);
                fixEmptyLinkedCloCells();
            }, 0);
        };
        fixNumberedTable(table, 'input[name*="[percentage]"]', 'assessment-total', '%');
        syncAllOtherBoxes(table);
        fixEmptyLinkedCloCells();
    }

    if (step === '5') {
        document.querySelectorAll('input[name*="[resource_text]"]').forEach(input => input.placeholder = 'Enter reference or learning material');
        document.querySelectorAll('input[name*="[resources]"]').forEach(input => input.placeholder = 'Enter required resources');
        syncAllOtherBoxes(document);
    }

    if (step === '6') {
        const assessorOptions = ['Students', 'Faculty', 'Program Leaders', 'Peer Reviewers', 'Other'];
        const methodOptions = ['Direct', 'Indirect'];
        document.querySelectorAll('input[name*="[assessor]"]').forEach(input => makeSelectFromInput(input, assessorOptions, '-- Select assessor --', 'Please specify'));
        document.querySelectorAll('input[name*="[assessment_method]"]').forEach(input => makeSelectFromInput(input, methodOptions, '-- Select method --', ''));
        syncAllOtherBoxes(document);
    }
})();