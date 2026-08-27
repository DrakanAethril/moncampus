<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Figures, aliases and teams - design/validated/gamification.md, lot 7.
 *
 * The two unique indexes on `game_alias` are the feature, not decoration. One keeps a student to a
 * single alias per period; the other, on (program, period, figure), is what **guarantees the
 * patronym is unique in the class** - it settles two simultaneous choices landing on Lovelace, which
 * no application check can.
 *
 * `game_team_set` points at a saved `group_batch` and holds nothing of its own: a lot **is** the
 * period's teams, and `group_batch.groups` is a frozen list of lists of student ids rather than a
 * relation, so a team keeps the members it was drawn with.
 *
 * The catalogue seeded below is the **amorce** of §4, decision 8 - ten to twelve names per filière,
 * taken from the design's own list - and every row ships `reviewed = 0` on purpose. A wrong notice
 * in a device that claims to be pedagogical is worse than no notice at all, and the design asks for
 * sixty entries per filière: that is documentary work for a human, and the settings screen prints
 * the tally until somebody has done it.
 */
final class Version20260827200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Figures, aliases and teams of the campus game, with an unreviewed starter catalogue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_figure (id INT AUTO_INCREMENT NOT NULL, surname VARCHAR(60) NOT NULL, full_name VARCHAR(120) NOT NULL, dates VARCHAR(60) DEFAULT NULL, notice LONGTEXT DEFAULT NULL, track VARCHAR(10) NOT NULL, active TINYINT NOT NULL, reviewed TINYINT NOT NULL, INDEX idx_game_figure_track (track, active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_alias (id INT AUTO_INCREMENT NOT NULL, offered_figures JSON NOT NULL, offered_at DATETIME NOT NULL, chosen_at DATETIME DEFAULT NULL, attributed_by_default TINYINT NOT NULL, student_id INT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, figure_id INT DEFAULT NULL, INDEX IDX_D35F121BCB944F1A (student_id), INDEX IDX_D35F121B3EB8070A (program_id), INDEX IDX_D35F121BEC8B7ADE (period_id), INDEX IDX_D35F121B5C011B5 (figure_id), UNIQUE INDEX uniq_game_alias_student (student_id, period_id, program_id), UNIQUE INDEX uniq_game_alias_figure (program_id, period_id, figure_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_team_set (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, group_batch_id INT NOT NULL, INDEX IDX_17C3505E3EB8070A (program_id), INDEX IDX_17C3505EEC8B7ADE (period_id), INDEX IDX_17C3505EFAAEF205 (group_batch_id), UNIQUE INDEX uniq_game_team_set (program_id, period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game_alias ADD CONSTRAINT FK_D35F121BCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_alias ADD CONSTRAINT FK_D35F121B3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_alias ADD CONSTRAINT FK_D35F121BEC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_alias ADD CONSTRAINT FK_D35F121B5C011B5 FOREIGN KEY (figure_id) REFERENCES game_figure (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE game_team_set ADD CONSTRAINT FK_17C3505E3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_team_set ADD CONSTRAINT FK_17C3505EEC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_team_set ADD CONSTRAINT FK_17C3505EFAAEF205 FOREIGN KEY (group_batch_id) REFERENCES group_batch (id) ON DELETE CASCADE');

        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Lovelace', 'Ada Lovelace', '1815-1852', 'A écrit, pour la machine analytique de Babbage, ce qui est tenu pour le premier algorithme destiné à être exécuté par une machine.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Turing', 'Alan Turing', '1912-1954', 'A formalisé la notion de calcul mécanique et posé les limites de ce qu''une machine peut décider.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Hopper', 'Grace Hopper', '1906-1992', 'A conçu le premier compilateur et défendu l''idée qu''un programme puisse s''écrire dans une langue proche de la nôtre.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Boole', 'George Boole', '1815-1864', 'A donné à la logique une forme algébrique — celle sur laquelle reposent les circuits et les conditions de tous tes programmes.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'von Neumann', 'John von Neumann', '1903-1957', 'A décrit l''architecture où le programme est rangé dans la même mémoire que les données.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Hamilton', 'Margaret Hamilton', 'née en 1936', 'A dirigé l''équipe qui a écrit le logiciel de bord d''Apollo, et popularisé l''expression « génie logiciel ».', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Dijkstra', 'Edsger Dijkstra', '1930-2002', 'A donné l''algorithme du plus court chemin et fait de la programmation une discipline qui se démontre.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Ritchie', 'Dennis Ritchie', '1941-2011', 'A créé le langage C et, avec Thompson, le système Unix dont descendent la plupart des systèmes actuels.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Liskov', 'Barbara Liskov', 'née en 1939', 'A énoncé le principe de substitution qui porte son nom, au cœur de la conception par objets.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Knuth', 'Donald Knuth', 'né en 1938', 'A écrit The Art of Computer Programming et fondé l''analyse rigoureuse des algorithmes.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Allen', 'Frances Allen', '1932-2020', 'A fondé l''optimisation de code dans les compilateurs, et fut la première femme lauréate du prix Turing.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SLAM', 'Johnson', 'Katherine Johnson', '1918-2020', 'A calculé les trajectoires des vols Mercury et Apollo, et vérifié à la main celles que les machines produisaient.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Perlman', 'Radia Perlman', 'née en 1951', 'A conçu le spanning tree protocol, qui empêche les boucles dans un réseau commuté.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Cerf', 'Vinton Cerf', 'né en 1943', 'A co-conçu TCP/IP, la pile de protocoles sur laquelle tient l''Internet.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Kahn', 'Robert Kahn', 'né en 1938', 'A co-conçu TCP/IP et posé le principe d''un réseau de réseaux indépendants les uns des autres.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Pouzin', 'Louis Pouzin', 'né en 1931', 'A inventé le datagramme au sein du réseau CYCLADES, principe repris par l''Internet.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Baran', 'Paul Baran', '1926-2011', 'A décrit la commutation par paquets et les réseaux distribués capables de survivre à la perte d''un nœud.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Postel', 'Jon Postel', '1943-1998', 'A tenu le registre des numéros de l''Internet et édité les RFC pendant près de trente ans.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Kleinrock', 'Leonard Kleinrock', 'né en 1934', 'A posé la théorie des files d''attente appliquée aux réseaux, et supervisé le premier message d''ARPANET.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Berners-Lee', 'Tim Berners-Lee', 'né en 1955', 'A inventé le Web : URL, HTTP et HTML, puis les a versés au domaine public.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Thompson', 'Ken Thompson', 'né en 1943', 'A créé Unix avec Ritchie, et le système de fichiers hiérarchique que tu utilises tous les jours.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Feinler', 'Elizabeth Feinler', 'née en 1931', 'A tenu le registre des noms de l''ARPANET et défini les domaines .com, .org et .net.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Jacobson', 'Van Jacobson', 'né en 1950', 'A conçu les algorithmes de contrôle de congestion sans lesquels l''Internet se serait effondré sous sa propre charge.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('SISR', 'Lamarr', 'Hedy Lamarr', '1914-2000', 'A breveté l''étalement de spectre par saut de fréquence, ancêtre des transmissions sans fil actuelles.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Pacioli', 'Luca Pacioli', '1447-1517', 'A publié la première description imprimée de la comptabilité en partie double.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Savary', 'Jacques Savary', '1622-1690', 'A rédigé Le Parfait Négociant et inspiré l''ordonnance qui a fondé le droit commercial français.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Cotrugli', 'Benedetto Cotrugli', '1416-1469', 'A décrit la partie double trente ans avant Pacioli, dans un traité resté longtemps manuscrit.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Stevin', 'Simon Stevin', '1548-1620', 'A introduit les fractions décimales dans le calcul et appliqué la partie double aux finances publiques.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Fayol', 'Henri Fayol', '1841-1925', 'A formulé les fonctions de l''administration : prévoir, organiser, commander, coordonner, contrôler.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Schmalenbach', 'Eugen Schmalenbach', '1873-1955', 'A conçu le plan comptable normalisé dont descendent les plans comptables européens.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Besta', 'Fabio Besta', '1845-1922', 'A fait de la comptabilité une discipline de contrôle économique plutôt qu''un simple registre.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Wedgwood', 'Josiah Wedgwood', '1730-1795', 'A tenu une comptabilité de coûts pour fixer ses prix, l''un des premiers usages du calcul de revient.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Ross', 'Mary Ross', '1908-2008', 'A mené les calculs financiers et techniques des programmes aérospatiaux de Lockheed.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('CG', 'Washington', 'Mary T. Washington', '1906-2005', 'Première femme afro-américaine expert-comptable agréée aux États-Unis, et formatrice de toute une génération.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Boucicaut', 'Aristide Boucicaut', '1810-1877', 'A inventé le grand magasin moderne : prix affichés, entrée libre, retour possible.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Bleustein-Blanchet', 'Marcel Bleustein-Blanchet', '1906-1996', 'A fondé Publicis et fait de la publicité un métier structuré en France.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Leclerc', 'Édouard Leclerc', '1926-2012', 'A construit la distribution à prix bas en s''affranchissant des marges imposées.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Fournier', 'Marcel Fournier', '1914-1985', 'A co-fondé Carrefour et introduit l''hypermarché en France.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Kotler', 'Philip Kotler', 'né en 1931', 'A donné au marketing sa forme enseignée : segmentation, ciblage, positionnement.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Levitt', 'Theodore Levitt', '1925-2006', 'A montré, dans « Marketing Myopia », qu''une entreprise vend un besoin et non un produit.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Ash', 'Mary Kay Ash', '1918-2001', 'A bâti un réseau de vente directe fondé sur la formation et la reconnaissance des vendeuses.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Lauder', 'Estée Lauder', '1908-2004', 'A fondé sa marque sur l''échantillon offert et la démonstration en magasin.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Walton', 'Sam Walton', '1918-1992', 'A fait de la logistique et du réapprovisionnement l''arme centrale de la grande distribution.', 1, 0)");
        $this->addSql("INSERT INTO game_figure (track, surname, full_name, dates, notice, active, reviewed) VALUES ('MCO', 'Walker', 'Madam C. J. Walker', '1867-1919', 'A construit un réseau national de vente et de formation, et fut l''une des premières femmes d''affaires millionnaires aux États-Unis.', 1, 0)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_team_set');
        $this->addSql('DROP TABLE game_alias');
        $this->addSql('DROP TABLE game_figure');
    }
}
