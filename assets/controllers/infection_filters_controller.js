import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['query', 'status', 'row', 'empty'];

    connect() {
        const status = new URLSearchParams(window.location.search).get('infection_status');
        if (status && [...this.statusTarget.options].some((option) => option.value === status)) {
            this.statusTarget.value = status;
            this.filter();
        }
    }

    filter() {
        const query = this.queryTarget.value.toLowerCase().trim();
        const status = this.statusTarget.value;
        let visible = 0;

        this.rowTargets.forEach((row) => {
            const matchesQuery = !query || row.dataset.search.includes(query);
            const matchesStatus = !status || row.dataset.status === status;
            row.hidden = !(matchesQuery && matchesStatus);
            if (!row.hidden) visible += 1;
        });

        this.emptyTarget.hidden = visible !== 0;
    }
}

