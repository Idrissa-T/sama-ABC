<?php
/**
 * Classe de génération des états PDF de la plateforme.
 *
 * Étend FPDF pour fournir l'en-tête et le pied de page communs à tous les
 * états, ainsi que des aides au tracé de tableaux.
 *
 * Note importante sur l'encodage : FPDF travaille avec les polices standard
 * PDF, encodées en ISO-8859-1 (latin1). Or l'application est intégralement
 * en UTF-8. Toute chaîne doit donc être convertie avant écriture, ce qui est
 * le rôle de la méthode txt().
 */

declare(strict_types=1);

require_once __DIR__ . '/fpdf/fpdf.php';

class EtatPdf extends FPDF
{
    private string $titreEtat;
    private string $sousTitre;
    private string $editePar;

    public function __construct(string $titreEtat, string $sousTitre = '',
                                string $editePar = '', string $orientation = 'P')
    {
        parent::__construct($orientation, 'mm', 'A4');

        $this->titreEtat = $titreEtat;
        $this->sousTitre = $sousTitre;
        $this->editePar  = $editePar;

        $this->SetTitle($titreEtat, true);
        $this->SetAuthor(APP_ENTREPRISE, true);
        $this->SetCreator('Plateforme ABC — Master CCA ESP Dakar', true);
        $this->SetMargins(12, 12, 12);
        $this->SetAutoPageBreak(true, 20);
        $this->AliasNbPages();
    }

    /** Convertit une chaîne UTF-8 vers le latin1 attendu par FPDF. */
    public function txt(?string $chaine): string
    {
        $c = (string) ($chaine ?? '');
        // Remplace d'abord les caractères absents de latin1
        $c = str_replace(
            ['œ', 'Œ', '—', '–', '’', '‘', '“', '”', '≥', '≤', '×', '€', 'Σ'],
            ['oe', 'OE', '-', '-', "'", "'", '"', '"', '>=', '<=', 'x', 'EUR', 'Somme'],
            $c
        );
        $converti = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $c);
        return $converti === false ? $c : $converti;
    }

    /** Montant formaté à la française, sans symbole. */
    public function montant(?float $valeur, int $decimales = 0): string
    {
        if ($valeur === null) {
            return '-';
        }
        return number_format($valeur, $decimales, ',', ' ');
    }

    // -----------------------------------------------------------------
    //  En-tête de page
    // -----------------------------------------------------------------
    public function Header(): void
    {
        // Bandeau de couleur brique
        $this->SetFillColor(168, 69, 44);
        $this->Rect(0, 0, $this->GetPageWidth(), 22, 'F');

        // Logo : motif de brique dessiné en vectoriel
        $this->SetFillColor(255, 255, 255);
        $this->Rect(12, 7, 5.5, 3.4, 'F');
        $this->Rect(18.5, 7, 5.5, 3.4, 'F');
        $this->Rect(12, 11.4, 5.5, 3.4, 'F');
        $this->Rect(18.5, 11.4, 5.5, 3.4, 'F');

        $this->SetTextColor(255, 255, 255);
        $this->SetXY(28, 6);
        $this->SetFont('Helvetica', 'B', 13);
        $this->Cell(100, 5, $this->txt(APP_ENTREPRISE), 0, 2, 'L');

        $this->SetFont('Helvetica', '', 8.5);
        $this->Cell(100, 4.5, $this->txt('Comptabilité par activités (ABC)'), 0, 0, 'L');

        // Titre de l'état, aligné à droite
        $this->SetXY(-100, 7);
        $this->SetFont('Helvetica', 'B', 10);
        $this->Cell(88, 5, $this->txt($this->titreEtat), 0, 2, 'R');

        if ($this->sousTitre !== '') {
            $this->SetFont('Helvetica', '', 8.5);
            $this->Cell(88, 4.5, $this->txt($this->sousTitre), 0, 0, 'R');
        }

        $this->SetTextColor(0, 0, 0);
        $this->SetY(30);
    }

    // -----------------------------------------------------------------
    //  Pied de page
    // -----------------------------------------------------------------
    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(12, $this->GetY(), $this->GetPageWidth() - 12, $this->GetY());

        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(110, 110, 110);

        $gauche = 'Édité le ' . date('d/m/Y') . ' à ' . date('H:i');
        if ($this->editePar !== '') {
            $gauche .= ' par ' . $this->editePar;
        }
        $this->Cell(0, 5, $this->txt($gauche), 0, 0, 'L');
        $this->Cell(0, 5, $this->txt('Page ' . $this->PageNo() . ' / {nb}'), 0, 0, 'R');

        $this->SetTextColor(0, 0, 0);
    }

    // -----------------------------------------------------------------
    //  Aides au tracé
    // -----------------------------------------------------------------

    /** Titre de section. */
    public function titreSection(string $texte): void
    {
        $this->Ln(3);
        $this->SetFont('Helvetica', 'B', 10.5);
        $this->SetTextColor(125, 49, 32);
        $this->Cell(0, 6, $this->txt($texte), 0, 1, 'L');
        $this->SetDrawColor(168, 69, 44);
        $this->SetLineWidth(0.4);
        $this->Line(12, $this->GetY(), $this->GetPageWidth() - 12, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    /** Paragraphe explicatif. */
    public function paragraphe(string $texte, float $taille = 8.5): void
    {
        $this->SetFont('Helvetica', '', $taille);
        $this->SetTextColor(70, 70, 70);
        $this->MultiCell(0, 4.2, $this->txt($texte), 0, 'J');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1);
    }

    /**
     * Ajuste un texte à la largeur d'une cellule.
     *
     * FPDF ne tronque pas : un libellé trop long déborde sur la colonne
     * voisine. Cette méthode le raccourcit et ajoute des points de suspension,
     * en tenant compte de la largeur réelle des caractères de la police.
     */
    public function ajuster(string $texteLatin1, float $largeur): string
    {
        $marge = 1.6;                     // rembourrage interne des cellules
        $dispo = max(1.0, $largeur - $marge);

        if ($this->GetStringWidth($texteLatin1) <= $dispo) {
            return $texteLatin1;
        }

        $suffixe = '...';
        $dispo  -= $this->GetStringWidth($suffixe);

        $coupe = $texteLatin1;
        while ($coupe !== '' && $this->GetStringWidth($coupe) > $dispo) {
            $coupe = substr($coupe, 0, -1);
        }
        return rtrim($coupe) . $suffixe;
    }

    /**
     * Ligne d'en-tête de tableau.
     *
     * @param array<int, array{0:string, 1:float, 2:string}> $colonnes libellé, largeur, alignement
     */
    public function enteteTableau(array $colonnes): void
    {
        $this->SetFont('Helvetica', 'B', 7.6);
        $this->SetFillColor(238, 234, 232);
        $this->SetDrawColor(190, 190, 190);

        foreach ($colonnes as [$libelle, $largeur, $align]) {
            $this->Cell($largeur, 6,
                $this->ajuster($this->txt($libelle), $largeur), 1, 0, $align, true);
        }
        $this->Ln();
    }

    /**
     * Ligne de données.
     *
     * @param array<int, array{0:string, 1:float, 2:string}> $cellules
     */
    public function ligneTableau(array $cellules, bool $gras = false,
                                 ?array $couleurFond = null, ?array $couleurTexte = null): void
    {
        $this->SetFont('Helvetica', $gras ? 'B' : '', 7.6);

        if ($couleurFond !== null) {
            $this->SetFillColor($couleurFond[0], $couleurFond[1], $couleurFond[2]);
        }
        if ($couleurTexte !== null) {
            $this->SetTextColor($couleurTexte[0], $couleurTexte[1], $couleurTexte[2]);
        }

        foreach ($cellules as [$valeur, $largeur, $align]) {
            $this->Cell($largeur, 5.2,
                $this->ajuster($this->txt($valeur), $largeur), 1, 0, $align,
                $couleurFond !== null);
        }
        $this->Ln();

        $this->SetTextColor(0, 0, 0);
    }

    /** Encadré de mise en garde ou de conclusion. */
    public function encadre(string $titre, string $texte, string $ton = 'info'): void
    {
        $couleurs = [
            'info'    => [[232, 240, 246], [26, 82, 118]],
            'danger'  => [[250, 235, 233], [155, 45, 32]],
            'succes'  => [[233, 245, 238], [30, 105, 70]],
        ];
        [$fond, $texteCouleur] = $couleurs[$ton] ?? $couleurs['info'];

        $this->Ln(2);
        $yDebut = $this->GetY();

        $this->SetFillColor($fond[0], $fond[1], $fond[2]);
        $this->SetTextColor($texteCouleur[0], $texteCouleur[1], $texteCouleur[2]);
        $this->SetDrawColor($texteCouleur[0], $texteCouleur[1], $texteCouleur[2]);

        $largeur = $this->GetPageWidth() - 24;

        $this->SetFont('Helvetica', 'B', 8.5);
        $this->MultiCell($largeur, 5, $this->txt($titre), 0, 'L', true);

        $this->SetFont('Helvetica', '', 8);
        $this->MultiCell($largeur, 4.2, $this->txt($texte), 0, 'J', true);

        // Filet vertical de couleur à gauche de l'encadré
        $this->SetLineWidth(0.8);
        $this->Line(12, $yDebut, 12, $this->GetY());
        $this->SetLineWidth(0.2);

        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(190, 190, 190);
        $this->Ln(2);
    }

    /** Bloc d'indicateurs clés sur une ligne. */
    public function indicateurs(array $kpi): void
    {
        $largeur = ($this->GetPageWidth() - 24) / max(1, count($kpi));

        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(110, 110, 110);
        foreach ($kpi as [$libelle, $valeur]) {
            $this->Cell($largeur, 4.5, $this->txt($libelle), 0, 0, 'L');
        }
        $this->Ln();

        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        foreach ($kpi as [$libelle, $valeur]) {
            $this->Cell($largeur, 5.5, $this->txt($valeur), 0, 0, 'L');
        }
        $this->Ln(7);
    }

    /**
     * Histogramme horizontal simple, tracé en vectoriel.
     *
     * @param array<int, array{0:string, 1:float}> $donnees
     */
    public function histogramme(array $donnees, float $hauteurLigne = 5.5): void
    {
        if (!$donnees) {
            return;
        }
        $max = max(array_map(static fn($d) => abs($d[1]), $donnees));
        if ($max <= 0) {
            return;
        }

        $largeurMax = 95;
        $xBarre     = 55;

        foreach ($donnees as [$libelle, $valeur]) {
            $y = $this->GetY();

            $this->SetFont('Helvetica', '', 7.6);
            $this->Cell(42, $hauteurLigne,
                $this->ajuster($this->txt($libelle), 42), 0, 0, 'L');

            $largeur = $largeurMax * abs($valeur) / $max;
            $this->SetFillColor($valeur < 0 ? 192 : 168, $valeur < 0 ? 57 : 69,
                                $valeur < 0 ? 43 : 44);
            $this->Rect($xBarre, $y + 1, max(0.4, $largeur), $hauteurLigne - 2, 'F');

            $this->SetXY($xBarre + $largeur + 2, $y);
            $this->SetFont('Helvetica', '', 7.2);
            $this->SetTextColor(90, 90, 90);
            $this->Cell(40, $hauteurLigne, $this->montant($valeur), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
        }
        $this->Ln(2);
    }
}
