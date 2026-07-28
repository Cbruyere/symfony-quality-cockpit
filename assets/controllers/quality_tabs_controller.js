import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel'];

    static classes = ['active', 'inactive'];

    connect() {
        this.allowedTabs = this.tabTargets.map((tab) => tab.dataset.qualityTabsTabParam);
        this.boundHashChange = () => this.selectFromHash(false);

        window.addEventListener('hashchange', this.boundHashChange);
        this.selectFromHash(false);
    }

    disconnect() {
        window.removeEventListener('hashchange', this.boundHashChange);
    }

    select(event) {
        event.preventDefault();

        this.activate(event.currentTarget.dataset.qualityTabsTabParam, true);
    }

    navigate(event) {
        const currentIndex = this.tabTargets.indexOf(event.currentTarget);
        let nextIndex = currentIndex;

        if ('ArrowRight' === event.key) {
            nextIndex = (currentIndex + 1) % this.tabTargets.length;
        } else if ('ArrowLeft' === event.key) {
            nextIndex = (currentIndex - 1 + this.tabTargets.length) % this.tabTargets.length;
        } else if ('Home' === event.key) {
            nextIndex = 0;
        } else if ('End' === event.key) {
            nextIndex = this.tabTargets.length - 1;
        } else {
            return;
        }

        event.preventDefault();

        const nextTab = this.tabTargets[nextIndex];
        this.activate(nextTab.dataset.qualityTabsTabParam, true);
        nextTab.focus();
    }

    selectFromHash(updateUrl) {
        const requestedTab = window.location.hash.replace('#', '');
        const tab = this.allowedTabs.includes(requestedTab) ? requestedTab : requestedTab.startsWith('phpmetrics-') ? 'phpmetrics' : 'phpunit';

        this.activate(tab, updateUrl);
        document.getElementById(requestedTab)?.scrollIntoView({block: 'start'});
    }

    activate(tabName, updateUrl) {
        this.tabTargets.forEach((tab) => {
            const selected = tab.dataset.qualityTabsTabParam === tabName;

            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.setAttribute('tabindex', selected ? '0' : '-1');
            this.applyClasses(tab, selected);
        });

        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.qualityTabsPanelParam !== tabName;
        });

        if (updateUrl) {
            window.history.replaceState(null, '', `#${tabName}`);
        }
    }

    applyClasses(tab, selected) {
        tab.classList.remove(...this.activeClasses, ...this.inactiveClasses);
        tab.classList.add(...(selected ? this.activeClasses : this.inactiveClasses));
    }
}

