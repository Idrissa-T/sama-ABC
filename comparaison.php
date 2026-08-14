<?php
/**
 * Comparaison méthode classique / méthode ABC.
 *
 * Écran de démonstration de la plateforme : il confronte l'imputation par
 * clé unique (main-d'œuvre directe) à l'imputation par inducteurs, et chiffre
 * le subventionnement croisé entre objets de coût.
 *
 * Un clic sur un objet affiche le détail activité par activité, ce qui permet
 * de justifier chaque franc imputé.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];

$lignes  = comparaisonCouts($periodeId);
$objetId = entierPositif($_GET['objet'] ?? 0);

// Objet sélectionné pour le détail (le premier par défaut)
$detail       = [];
$objetChoisi  = null;
if ($lignes) {
    foreach ($lignes as $l) {
        if ((int) $l['objet_cout_id'] === $objetId) {
            $objetChoisi = $l;
            break;
        }
    }
    if ($objetChoisi === null) {
        $objetChoisi = $lignes[0];
    }
    $detail = detailImputation($periodeId, (int) $objetChoisi['objet_cout_id']);
}

// Totaux de contrôle
$totClassique = array_sum(array_map(static fn($l) => (float) $l['indirect_classique'], $lignes));
$totAbc       = array_sum(array_map(static fn($l) => (float) $l['indirect_abc'], $lignes));

afficherEntete('Comparaison — méthode classique vs ABC', $periode);
?>

<div class="alert alert-info small">
  <strong>Méthode classique :</strong> les charges indirectes sont réparties au
  prorata d'une clé unique, ici la main-d'œuvre directe.
  <strong>Méthode ABC :</strong> chaque objet supporte les charges des activités
  qu'il consomme réellement, mesurées par les inducteurs.
  L'écart entre les deux est le <strong>subventionnement croisé</strong>.
</div>

<!-- ============ TABLEAU COMPARATIF ============ -->
<div class="card mb-4">
  <div class="card-header"><strong>Coût de revient par objet de coût</strong></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Objet de coût</th>
          <th class="text-end">Quantité</th>
          <th class="text-end">Coûts directs</th>
          <th class="text-end">Indirect<br>classique</th>
          <th class="text-end">Indirect<br>ABC</th>
          <th class="text-end">Subventionnement<br>croisé</th>
          <th class="text-end">Coût unit.<br>classique</th>
          <th class="text-end">Coût unit.<br>ABC</th>
          <th class="text-end">Écart<br>unitaire</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($lignes as $l):
          $uClas  = (float) $l['cout_unitaire_classique'];
          $uAbc   = (float) $l['cout_unitaire_abc'];
          $ecartU = $uAbc - $uClas;
          $subv   = (float) $l['subventionnement_croise'];
          $actif  = $objetChoisi !== null
                 && (int) $l['objet_cout_id'] === (int) $objetChoisi['objet_cout_id']; ?>
        <tr class="<?= $actif ? 'table-active' : '' ?>">
          <td>
            <span class="badge bg-secondary"><?= e($l['objet_code']) ?></span>
            <?= e($l['objet_libelle']) ?>
            <?php if ($l['statut_rentabilite_abc'] === 'DEFICITAIRE'): ?>
              <span class="badge bg-danger ms-1">Déficitaire</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= nb((float) $l['quantite_produite']) ?></td>
          <td class="text-end"><?= fcfa((float) $l['couts_directs']) ?></td>
          <td class="text-end"><?= fcfa((float) $l['indirect_classique']) ?></td>
          <td class="text-end"><?= fcfa((float) $l['indirect_abc']) ?></td>
          <td class="text-end <?= classeSigne($subv) ?>"><?= fcfa($subv) ?></td>
          <td class="text-end"><?= fcfa($uClas) ?></td>
          <td class="text-end fw-semibold"><?= fcfa($uAbc) ?></td>
          <td class="text-end <?= classeSigne(-$ecartU) ?>">
            <?= ($ecartU > 0 ? '+' : '') . fcfa($ecartU) ?>
          </td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-secondary"
               href="comparaison.php?periode=<?= $periodeId ?>&amp;objet=<?= (int) $l['objet_cout_id'] ?>">
              Détail
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-semibold">
        <tr>
          <td colspan="3">Total</td>
          <td class="text-end"><?= fcfa($totClassique) ?></td>
          <td class="text-end"><?= fcfa($totAbc) ?></td>
          <td class="text-end"><?= fcfa($totClassique - $totAbc) ?></td>
          <td colspan="4"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <div class="card-footer small text-muted">
    Les deux méthodes imputent le même total : elles ne diffèrent que par la
    <em>répartition</em>. Le total du subventionnement croisé vaut donc toujours zéro —
    ce qu'un produit ne supporte pas, un autre le supporte à sa place.
  </div>
</div>

<!-- ============ DÉTAIL DE L'IMPUTATION ============ -->
<?php if ($objetChoisi !== null): ?>
<div class="card mb-4">
  <div class="card-header">
    <strong>
      Détail de l'imputation —
      <?= e($objetChoisi['objet_code']) ?> <?= e($objetChoisi['objet_libelle']) ?>
    </strong>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr>
          <th>Activité</th>
          <th>Unité d'œuvre</th>
          <th class="text-end">Quantité<br>consommée</th>
          <th class="text-end">Coût unitaire<br>d'inducteur</th>
          <th class="text-end">Coût imputé</th>
          <th class="text-end">Part</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $totImpute = array_sum(array_map(static fn($d) => (float) $d['cout_impute'], $detail));
      foreach ($detail as $d):
          $part = $totImpute > 0 ? 100 * $d['cout_impute'] / $totImpute : 0; ?>
        <tr>
          <td>
            <span class="badge bg-secondary"><?= e($d['activite_code']) ?></span>
            <?= e($d['activite_libelle']) ?>
          </td>
          <td class="small text-muted"><?= e($d['unite_oeuvre']) ?></td>
          <td class="text-end"><?= nb((float) $d['quantite_inducteur']) ?></td>
          <td class="text-end"><?= fcfa((float) $d['cout_unitaire_inducteur'], 2) ?></td>
          <td class="text-end fw-semibold"><?= fcfa((float) $d['cout_impute']) ?></td>
          <td class="text-end"><?= pct($part, 1) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-semibold">
        <tr>
          <td colspan="4">Charges indirectes imputées par la méthode ABC</td>
          <td class="text-end"><?= fcfa($totImpute) ?></td>
          <td class="text-end">100,0 %</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- ============ LECTURE ÉCONOMIQUE ============ -->
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header"><strong>Décomposition du coût de revient ABC</strong></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr>
            <td>Matières consommées</td>
            <td class="text-end"><?= fcfa((float) $objetChoisi['cout_matieres']) ?></td>
          </tr>
          <tr>
            <td>Main-d'œuvre directe</td>
            <td class="text-end"><?= fcfa((float) $objetChoisi['cout_mod']) ?></td>
          </tr>
          <tr>
            <td>Charges indirectes (ABC)</td>
            <td class="text-end"><?= fcfa((float) $objetChoisi['indirect_abc']) ?></td>
          </tr>
          <tr class="fw-semibold border-top">
            <td>Coût de revient total</td>
            <td class="text-end"><?= fcfa((float) $objetChoisi['cout_total_abc']) ?></td>
          </tr>
          <tr>
            <td>Quantité produite</td>
            <td class="text-end"><?= nb((float) $objetChoisi['quantite_produite']) ?></td>
          </tr>
          <tr class="fw-semibold">
            <td>Coût de revient unitaire</td>
            <td class="text-end"><?= fcfa((float) $objetChoisi['cout_unitaire_abc']) ?></td>
          </tr>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100 <?= $objetChoisi['statut_rentabilite_abc'] === 'DEFICITAIRE' ? 'border-danger' : '' ?>">
      <div class="card-header"><strong>Diagnostic de rentabilité</strong></div>
      <div class="card-body">
        <?php
        $pv     = (float) $objetChoisi['prix_vente_unitaire'];
        $cu     = (float) $objetChoisi['cout_unitaire_abc'];
        $margeU = $pv - $cu;
        $hausse = $pv > 0 ? 100 * ($cu - $pv) / $pv : 0;
        ?>
        <table class="table table-sm mb-3">
          <tr>
            <td>Prix de vente unitaire</td>
            <td class="text-end"><?= fcfa($pv) ?></td>
          </tr>
          <tr>
            <td>Coût de revient unitaire ABC</td>
            <td class="text-end"><?= fcfa($cu) ?></td>
          </tr>
          <tr class="fw-semibold border-top">
            <td>Marge unitaire</td>
            <td class="text-end <?= classeSigne($margeU) ?>"><?= fcfa($margeU) ?></td>
          </tr>
          <tr>
            <td>Marge globale ABC</td>
            <td class="text-end <?= classeSigne((float) $objetChoisi['marge_abc']) ?>">
              <?= fcfa((float) $objetChoisi['marge_abc']) ?>
            </td>
          </tr>
          <tr>
            <td>Taux de marge ABC</td>
            <td class="text-end"><?= pct($objetChoisi['taux_marge_abc'] !== null
                ? (float) $objetChoisi['taux_marge_abc'] : null, 2) ?></td>
          </tr>
        </table>

        <?php if ($objetChoisi['statut_rentabilite_abc'] === 'DEFICITAIRE'): ?>
          <div class="alert alert-danger mb-0 small">
            <p class="fw-semibold mb-1">Produit vendu sous son coût de revient réel</p>
            <p class="mb-1">
              La méthode classique affichait une marge de
              <?= fcfa((float) $objetChoisi['marge_classique']) ?>, car ce produit était
              subventionné à hauteur de
              <?= fcfa(-1 * (float) $objetChoisi['subventionnement_croise']) ?>
              par les autres objets de coût.
            </p>
            <p class="mb-0">
              Leviers : porter le prix de vente à au moins <?= fcfa($cu) ?>
              (soit <?= pct($hausse, 1) ?> de hausse), allonger les séries pour diluer
              les activités de lot, ou réexaminer l'opportunité de la gamme.
            </p>
          </div>
        <?php else: ?>
          <div class="alert alert-success mb-0 small">
            <p class="mb-0">
              Produit rentable après imputation ABC.
              <?php if ((float) $objetChoisi['subventionnement_croise'] > 0): ?>
                Il était même <strong>surchargé</strong> de
                <?= fcfa((float) $objetChoisi['subventionnement_croise']) ?>
                par la méthode classique : sa rentabilité réelle est supérieure
                à ce que laissait croire la clé unique.
              <?php endif; ?>
            </p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2">
  <a href="rentabilite.php?periode=<?= $periodeId ?>" class="btn btn-brique">
    Analyse de rentabilité par objet
  </a>
  <?php if ($objetChoisi !== null): ?>
  <a href="export_pdf.php?periode=<?= $periodeId ?>&amp;etat=fiche&amp;objet=<?= (int) $objetChoisi['objet_cout_id'] ?>"
     class="btn btn-outline-secondary">
    Fiche PDF de <?= e($objetChoisi['objet_code']) ?>
  </a>
  <?php endif; ?>
  <a href="export_csv.php?periode=<?= $periodeId ?>&amp;etat=comparaison" class="btn btn-outline-secondary">
    Export CSV
  </a>
  <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
    Imprimer cet état
  </button>
</div>

<?php
audit('CONSULTATION', 'v_comparaison_couts', $periode['code'], 'Comparaison classique / ABC');
afficherPied();
