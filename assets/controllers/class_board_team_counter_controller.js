import WidgetController from '../class_board/widget_controller.js';

// Compteur d'équipes of the virtual board: points per team, ±1, saved with the board. Teams are
// typed by hand, or taken from a lot of groups - one team per group - and as many as wanted: the
// cards wrap and the widget scrolls. Nothing reaches the Jeu du campus.
/* stimulusFetch: 'lazy' */
export default class extends WidgetController {
    static targets = ['teams', 'names', 'addButton'];

    setup() {
        const data = this.teamsTarget.dataset;
        this.members = JSON.parse(data.members || '[]');
        let teams = Array.isArray(this.config.teams) ? this.config.teams.map((team) => ({ name: team.name || '', score: team.score || 0 })) : [];
        if (this.members.length > 0 && teams.length !== this.members.length) {
            teams = this.members.map((_, index) => teams[index] || { name: '', score: 0 });
        }
        if (teams.length === 0) {
            teams = [{ name: '', score: 0 }, { name: '', score: 0 }];
        }
        this.teams = teams;
        if (JSON.stringify(teams) !== JSON.stringify(this.config.teams || [])) {
            this.store({ teams });
        }
        this.render();
    }

    label(index) {
        const team = this.teams[index];
        if (team.name) {
            return team.name;
        }
        const template = this.members.length > 0 ? this.teamsTarget.dataset.groupLabel : this.teamsTarget.dataset.teamLabel;
        return template.replace('__N__', String(index + 1));
    }

    score(event) {
        const index = parseInt(event.currentTarget.dataset.index, 10);
        this.teams[index].score += parseInt(event.currentTarget.dataset.step, 10);
        this.store({ teams: this.teams });
        this.renderScores();
    }

    rename(event) {
        const index = parseInt(event.target.dataset.index, 10);
        this.teams[index].name = event.target.value.slice(0, 40);
        this.store({ teams: this.teams });
        this.renderScores();
    }

    addTeam() {
        if (this.members.length > 0) {
            return;
        }
        this.teams.push({ name: '', score: 0 });
        this.store({ teams: this.teams });
        this.render();
    }

    removeTeam(event) {
        if (this.teams.length <= 1 || this.members.length > 0) {
            return;
        }
        this.teams.splice(parseInt(event.currentTarget.dataset.index, 10), 1);
        this.store({ teams: this.teams });
        this.render();
    }

    resetScores() {
        this.teams.forEach((team) => { team.score = 0; });
        this.store({ teams: this.teams });
        this.renderScores();
    }

    render() {
        this.teamsTarget.innerHTML = '';
        this.teams.forEach((_, index) => {
            const card = document.createElement('div');
            card.className = 'cm-cb-teams__team';
            card.innerHTML = `<span class="cm-cb-teams__name"></span><b class="cm-cb-num cm-cb-teams__score"></b>
                <span class="cm-cb-w__buttons">
                    <button type="button" class="cm-cb-btn" data-index="${index}" data-step="-1" data-action="class-board-team-counter#score">−1</button>
                    <button type="button" class="cm-cb-btn cm-cb-btn--primary" data-index="${index}" data-step="1" data-action="class-board-team-counter#score">+1</button>
                </span>
                <span class="cm-cb-teams__members"></span>`;
            card.querySelector('.cm-cb-teams__members').textContent = (this.members[index] || []).join(', ');
            this.teamsTarget.appendChild(card);
        });
        this.renderScores();

        if (this.hasNamesTarget) {
            this.namesTarget.innerHTML = '';
            this.teams.forEach((team, index) => {
                const row = document.createElement('div');
                row.className = 'cm-cb-team-names__row';
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'cm-cb-input';
                input.maxLength = 40;
                input.value = team.name;
                input.placeholder = this.label(index);
                input.dataset.index = String(index);
                input.dataset.action = 'input->class-board-team-counter#rename';
                row.appendChild(input);
                if (this.members.length === 0 && this.teams.length > 1) {
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'cm-cb-btn cm-cb-btn--small';
                    remove.textContent = '×';
                    remove.dataset.index = String(index);
                    remove.dataset.action = 'class-board-team-counter#removeTeam';
                    row.appendChild(remove);
                }
                this.namesTarget.appendChild(row);
            });
            if (this.hasAddButtonTarget) {
                this.addButtonTarget.hidden = this.members.length > 0;
            }
        }
    }

    renderScores() {
        this.teamsTarget.querySelectorAll('.cm-cb-teams__team').forEach((card, index) => {
            card.querySelector('.cm-cb-teams__name').textContent = this.label(index);
            card.querySelector('.cm-cb-teams__score').textContent = String(this.teams[index].score);
        });
    }
}
