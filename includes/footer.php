</div>
    </main>
</div>

<script>
(function () {
    const toggle = document.getElementById('sidebarToggle');
    if (toggle) {
        if (localStorage.getItem('aqmsSidebar') === 'closed') document.body.classList.add('sidebar-collapsed');
        toggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('aqmsSidebar', document.body.classList.contains('sidebar-collapsed') ? 'closed' : 'open');
        });
    }

    const path = window.location.pathname;
    if (!path.includes('/faculty/course_edit.php')) return;

    const params = new URLSearchParams(window.location.search);
    const step = params.get('step') || '1';

    if (step === '2') {
        const oldTable = document.getElementById('clo-table');
        const form = document.querySelector('form fieldset');
        if (!oldTable || !form) return;

        const addButton = document.querySelector('button[onclick="addCloRow()"]');
        if (addButton) addButton.remove();

        const rows = Array.from(oldTable.querySelectorAll('tbody tr'));
        const sections = [
            {title: '1.0 Knowledge and understanding', category: 'Knowledge and Understanding', prefix: '1'},
            {title: '2.0 Skills', category: 'Skills', prefix: '2'},
            {title: '3.0 Values, autonomy, and responsibility', category: 'Values, Autonomy, and Responsibility', prefix: '3'}
        ];

        const builder = document.createElement('div');
        builder.className = 'clo-builder';

        function getRowCategory(row) {
            const select = row.querySelector('select[name*="[category]"]');
            return select ? select.value : 'Knowledge and Understanding';
        }

        function getRowIndex(row) {
            const input = row.querySelector('input[name^="clo["]');
            if (!input) return Date.now();
            const match = input.name.match(/clo\[(\d+)\]/);
            return match ? match[1] : Date.now();
        }

        function makeField(label, child) {
            const wrap = document.createElement('div');
            const lab = document.createElement('label');
            lab.textContent = label;
            wrap.appendChild(lab);
            wrap.appendChild(child);
            return wrap;
        }

        function emptyInput(name, value, type) {
            const input = document.createElement(type === 'textarea' ? 'textarea' : 'input');
            if (type !== 'textarea') input.type = type || 'text';
            input.name = name;
            input.value = value || '';
            return input;
        }

        function collectPloLabels(row, newIndex) {
            const labels = Array.from(row.querySelectorAll('input[name*="[plos]"]')).map(input => {
                const label = input.closest('label');
                const code = label ? label.textContent.trim() : input.value;
                return {value: input.value, code: code, checked: input.checked};
            });
            const grid = document.createElement('div');
            grid.className = 'plo-picker';
            labels.forEach(item => {
                const label = document.createElement('label');
                label.className = 'checkbox-pill';
                label.innerHTML = '<input type="checkbox" name="clo[' + newIndex + '][plos][]" value="' + item.value + '" ' + (item.checked ? 'checked' : '') + '> ' + item.code;
                grid.appendChild(label);
            });
            if (!labels.length) {
                const warn = document.createElement('div');
                warn.className = 'empty-plo-warning';
                warn.textContent = 'No PLOs are linked to this program yet. Run database/seed_default_plos.sql, then refresh this page.';
                return warn;
            }
            return grid;
        }

        function collectSkills(row, newIndex) {
            const labels = Array.from(row.querySelectorAll('input[name*="[jahiziah]"]')).map(input => {
                const label = input.closest('label');
                const code = label ? label.textContent.trim() : input.value;
                return {value: input.value, code: code, checked: input.checked};
            });
            const grid = document.createElement('div');
            grid.className = 'checkbox-grid';
            labels.forEach(item => {
                const label = document.createElement('label');
                label.className = 'checkbox-pill';
                label.innerHTML = '<input type="checkbox" name="clo[' + newIndex + '][jahiziah][]" value="' + item.value + '" ' + (item.checked ? 'checked' : '') + '> ' + item.code;
                grid.appendChild(label);
            });
            return grid;
        }

        function makeCloCard(index, category, row, defaultCode) {
            const card = document.createElement('div');
            card.className = 'clo-card';

            const savedCode = row ? (row.querySelector('input[name*="[code]"]')?.value || '') : '';
            const codeValue = savedCode || defaultCode || '';
            const descValue = row ? (row.querySelector('input[name*="[description]"]')?.value || '') : '';
            const teachValue = row ? (row.querySelector('input[name*="[teaching_strategies]"]')?.value || '') : '';
            const assessValue = row ? (row.querySelector('input[name*="[assessment_methods]"]')?.value || '') : '';

            const hiddenCat = emptyInput('clo[' + index + '][category]', category, 'hidden');
            const code = emptyInput('clo[' + index + '][code]', codeValue, 'text');
            const desc = emptyInput('clo[' + index + '][description]', descValue, 'textarea');
            const teach = emptyInput('clo[' + index + '][teaching_strategies]', teachValue, 'textarea');
            const assess = emptyInput('clo[' + index + '][assessment_methods]', assessValue, 'textarea');

            const codeWrap = makeField('Code', code);
            codeWrap.appendChild(hiddenCat);
            card.appendChild(codeWrap);
            card.appendChild(makeField('Course Learning Outcome', desc));

            const ploSourceRow = row || rows[0];
            card.appendChild(makeField('Code of PLOs aligned with the program', collectPloLabels(ploSourceRow, index)));
            card.appendChild(makeField('Teaching Strategies', teach));
            card.appendChild(makeField('Assessment Methods', assess));

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'icon-btn clo-card-remove';
            remove.textContent = '✕';
            remove.addEventListener('click', function () { card.remove(); });
            card.appendChild(remove);

            const skillsWrap = document.createElement('div');
            skillsWrap.style.gridColumn = '2 / span 4';
            skillsWrap.appendChild(makeField('Jahiziah Skills', collectSkills(row || rows[0], index)));
            card.appendChild(skillsWrap);

            return card;
        }

        let nextIndex = rows.length || 0;
        sections.forEach(section => {
            const box = document.createElement('div');
            box.className = 'clo-section';
            const title = document.createElement('div');
            title.className = 'clo-section-title';
            title.innerHTML = '<span>' + section.title + '</span>';
            const add = document.createElement('button');
            add.type = 'button';
            add.className = 'btn btn-outline btn-sm';
            add.textContent = '+ Add CLO';
            title.appendChild(add);
            box.appendChild(title);

            const matching = rows.filter(row => getRowCategory(row) === section.category);
            const initial = matching.length ? matching : [null, null];
            initial.forEach((row, i) => {
                const idx = row ? getRowIndex(row) : (nextIndex++);
                box.appendChild(makeCloCard(idx, section.category, row, section.prefix + '.' + (i + 1)));
            });

            add.addEventListener('click', function () {
                const count = box.querySelectorAll('.clo-card').length + 1;
                box.appendChild(makeCloCard(nextIndex++, section.category, null, section.prefix + '.' + count));
            });

            builder.appendChild(box);
        });

        oldTable.closest('div').replaceWith(builder);
    }

    if (step === '3') {
        const table = document.getElementById('topic-table');
        if (!table) return;

        function renumberTopics() {
            table.querySelectorAll('tbody tr').forEach((row, index) => {
                const first = row.querySelector('td');
                if (first) first.textContent = (index + 1) + '.';
            });
        }

        window.addTopicRow = function () {
            const tbody = table.querySelector('tbody');
            const i = tbody.querySelectorAll('tr').length;
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + (i + 1) + '.</td>' +
                '<td><input type="text" name="topic[' + i + '][topic_text]"></td>' +
                '<td><input type="number" name="topic[' + i + '][contact_hours]" step="0.5"></td>' +
                '<td><button type="button" class="icon-btn topic-remove">✕</button></td>';
            tbody.appendChild(tr);
        };

        table.addEventListener('click', function (event) {
            const btn = event.target.closest('.icon-btn, .topic-remove');
            if (!btn) return;
            setTimeout(renumberTopics, 0);
        });
        renumberTopics();
    }

    if (step === '4') {
        const table = document.getElementById('assess-table');
        if (!table) return;

        table.classList.add('official-table');
        const options = ['Written test', 'Oral test', 'Oral presentation', 'Group project', 'Essay', 'Other'];

        function buildActivityControl(input) {
            if (!input || input.dataset.done === '1') return;
            input.dataset.done = '1';
            const oldValue = input.value || '';
            const wrapper = document.createElement('div');
            const select = document.createElement('select');
            const hidden = document.createElement('input');
            const other = document.createElement('input');

            hidden.type = 'hidden';
            hidden.name = input.name;
            other.type = 'text';
            other.placeholder = 'Specify other assessment activity';
            other.className = 'other-box';

            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = '-- Select --';
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
                hidden.value = oldValue;
            }

            function sync() {
                if (select.value === 'Other') {
                    other.classList.add('show');
                    hidden.value = other.value.trim();
                } else {
                    other.classList.remove('show');
                    hidden.value = select.value;
                }
            }

            select.addEventListener('change', sync);
            other.addEventListener('input', sync);
            wrapper.appendChild(select);
            wrapper.appendChild(other);
            wrapper.appendChild(hidden);
            input.replaceWith(wrapper);
        }

        function applyAssessmentControls() {
            table.querySelectorAll('input[name*="[activity_name]"]').forEach(buildActivityControl);
        }

        const oldAddAssessRow = window.addAssessRow;
        window.addAssessRow = function () {
            if (typeof oldAddAssessRow === 'function') oldAddAssessRow();
            applyAssessmentControls();
        };

        applyAssessmentControls();
    }
})();
</script>

</body>
</html>