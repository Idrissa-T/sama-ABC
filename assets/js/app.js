/**
 * A & P Briques — ABC
 * Contrôles de saisie côté client et graphiques du tableau de bord.
 */
'use strict';

// =====================================================================
//  1. MATRICE DES CLÉS : contrôle « somme = 100 % » en temps réel
// =====================================================================
(function () {
  const table = document.getElementById('tableCles');
  if (!table) {
    return;
  }

  const TOLERANCE = 0.0001;
  const bouton    = document.getElementById('btnEnregistrer');
  const message   = document.getElementById('messageControle');

  function sommeLigne(ligne) {
    let total = 0;
    ligne.querySelectorAll('.cle-input').forEach(function (champ) {
      const v = parseFloat(String(champ.value).replace(',', '.'));
      if (!isNaN(v)) {
        total += v;
      }
    });
    return total;
  }

  function majLigne(ligne) {
    const total   = sommeLigne(ligne);
    const cellule = ligne.querySelector('.total-ligne');
    const conforme = Math.abs(total - 100) < TOLERANCE;

    cellule.textContent = total.toLocaleString('fr-FR', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2
    }) + ' %';

    cellule.classList.toggle('bg-success-subtle', conforme);
    cellule.classList.toggle('text-success', conforme);
    cellule.classList.toggle('bg-danger-subtle', !conforme);
    cellule.classList.toggle('text-danger', !conforme);
    ligne.classList.toggle('ligne-anomalie', !conforme);

    return conforme;
  }

  function majGlobal() {
    let anomalies = 0;
    table.querySelectorAll('tbody tr').forEach(function (ligne) {
      if (!majLigne(ligne)) {
        anomalies += 1;
      }
    });

    if (bouton) {
      bouton.disabled = anomalies > 0;
    }
    if (message) {
      if (anomalies === 0) {
        message.textContent = 'Toutes les ressources sont réparties à 100 %.';
        message.className = 'align-self-center small text-success';
      } else {
        message.textContent = anomalies + ' ressource(s) à corriger avant enregistrement.';
        message.className = 'align-self-center small text-danger';
      }
    }
  }

  table.addEventListener('input', function (ev) {
    if (ev.target.classList.contains('cle-input')) {
      majGlobal();
    }
  });

  // Sélection du contenu au focus : saisie plus rapide dans la matrice
  table.addEventListener('focusin', function (ev) {
    if (ev.target.classList.contains('cle-input')) {
      ev.target.select();
    }
  });

  // Répartition égale des lignes encore vides
  const btnEgal = document.getElementById('btnRepartirEgal');
  if (btnEgal) {
    btnEgal.addEventListener('click', function () {
      table.querySelectorAll('tbody tr').forEach(function (ligne) {
        if (sommeLigne(ligne) > 0) {
          return;
        }
        const champs = ligne.querySelectorAll('.cle-input');
        const part   = Math.floor((10000 / champs.length)) / 100;
        let cumul    = 0;
        champs.forEach(function (champ, i) {
          const v = (i === champs.length - 1) ? Math.round((100 - cumul) * 100) / 100 : part;
          cumul += v;
          champ.value = v;
        });
      });
      majGlobal();
    });
  }

  // Garde-fou au submit (double sécurité avec la validation PHP)
  const formulaire = document.getElementById('formCles');
  if (formulaire) {
    formulaire.addEventListener('submit', function (ev) {
      let anomalies = 0;
      table.querySelectorAll('tbody tr').forEach(function (ligne) {
        if (!majLigne(ligne)) {
          anomalies += 1;
        }
      });
      if (anomalies > 0) {
        ev.preventDefault();
        window.alert('Impossible d\'enregistrer : ' + anomalies +
                     ' ressource(s) ne sont pas réparties à 100 %.');
      }
    });
  }

  majGlobal();
})();

// =====================================================================
//  2. GRAPHIQUES DU TABLEAU DE BORD
// =====================================================================
(function () {
  if (typeof window.ABC_DATA === 'undefined' || typeof Chart === 'undefined') {
    return;
  }

  const d = window.ABC_DATA;

  const ARGILE  = '#a8452c';
  const ARGILE2 = '#d98b6f';
  const BETON   = '#5c6b73';
  const OR      = '#c8963e';
  const ROUGE   = '#c0392b';
  const VERT    = '#2e7d54';

  Chart.defaults.font.family =
    "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
  Chart.defaults.font.size = 12;

  const fcfa = function (v) {
    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(v) + ' FCFA';
  };

  // --- 2.1 Coût unitaire : classique vs ABC, avec le prix de vente ---
  const cvA = document.getElementById('graphComparaison');
  if (cvA && d.objets) {
    new Chart(cvA, {
      type: 'bar',
      data: {
        labels: d.objets.map(function (o) { return o.code; }),
        datasets: [
          {
            label: 'Coût unitaire classique',
            data: d.objets.map(function (o) { return o.classique; }),
            backgroundColor: BETON
          },
          {
            label: 'Coût unitaire ABC',
            data: d.objets.map(function (o) { return o.abc; }),
            backgroundColor: ARGILE
          },
          {
            label: 'Prix de vente',
            data: d.objets.map(function (o) { return o.prix; }),
            type: 'line',
            borderColor: OR,
            backgroundColor: OR,
            borderWidth: 2,
            pointRadius: 4,
            fill: false
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              title: function (items) {
                const o = d.objets[items[0].dataIndex];
                return o.code + ' — ' + o.libelle;
              },
              label: function (ctx) {
                return ctx.dataset.label + ' : ' + fcfa(ctx.parsed.y);
              },
              afterBody: function (items) {
                const o = d.objets[items[0].dataIndex];
                return o.abc > o.prix
                  ? '⚠ Vendu sous son coût de revient réel'
                  : '';
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function (v) {
                return new Intl.NumberFormat('fr-FR').format(v);
              }
            }
          }
        }
      }
    });
  }

  // --- 2.2 Répartition des charges par processus ---
  const cvP = document.getElementById('graphProcessus');
  if (cvP && d.processus) {
    new Chart(cvP, {
      type: 'doughnut',
      data: {
        labels: d.processus.map(function (p) { return p.libelle; }),
        datasets: [{
          data: d.processus.map(function (p) { return p.cout; }),
          backgroundColor: [ARGILE, BETON, OR, ARGILE2, VERT, ROUGE]
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                const total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                const part  = total > 0 ? (100 * ctx.parsed / total).toFixed(1) : 0;
                return ctx.label + ' : ' + fcfa(ctx.parsed) + ' (' + part + ' %)';
              }
            }
          }
        }
      }
    });
  }

  // --- 2.3 Pareto des activités : barres + courbe de cumul ---
  const cvR = document.getElementById('graphPareto');
  if (cvR && d.pareto) {
    new Chart(cvR, {
      data: {
        labels: d.pareto.map(function (p) { return p.code; }),
        datasets: [
          {
            type: 'bar',
            label: 'Coût de l\'activité',
            data: d.pareto.map(function (p) { return p.cout; }),
            backgroundColor: ARGILE,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Cumul (%)',
            data: d.pareto.map(function (p) { return p.cumul; }),
            borderColor: OR,
            backgroundColor: OR,
            borderWidth: 2,
            pointRadius: 3,
            yAxisID: 'y1',
            fill: false
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: {
          y: {
            beginAtZero: true,
            position: 'left',
            ticks: {
              callback: function (v) {
                return new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(v);
              }
            }
          },
          y1: {
            beginAtZero: true,
            max: 100,
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { callback: function (v) { return v + ' %'; } }
          }
        }
      }
    });
  }

  // --- 2.4 Subventionnement croisé ---
  const cvS = document.getElementById('graphSubvention');
  if (cvS && d.objets) {
    new Chart(cvS, {
      type: 'bar',
      data: {
        labels: d.objets.map(function (o) { return o.code; }),
        datasets: [{
          label: 'Subventionnement croisé',
          data: d.objets.map(function (o) { return o.subvention; }),
          backgroundColor: d.objets.map(function (o) {
            return o.subvention < 0 ? ROUGE : VERT;
          })
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                const v = ctx.parsed.x;
                const sens = v < 0
                  ? 'subventionné par les autres produits'
                  : 'surchargé par la méthode classique';
                return fcfa(Math.abs(v)) + ' — ' + sens;
              }
            }
          }
        },
        scales: {
          x: {
            ticks: {
              callback: function (v) {
                return new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(v);
              }
            }
          }
        }
      }
    });
  }
})();
