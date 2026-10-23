import * as critical from 'critical';

// Définition des écrans à tester (Breakpoints Bootstrap indispensables)
const viewports = [
  { width: 375, height: 667 },   // Mobile (XS / SM)
  { width: 768, height: 1024 },  // Tablette (MD)
  { width: 1300, height: 900 }   // Desktop (LG / XL)
];

// Options de nettoyage pour éviter que Bootstrap ne perde ses styles dynamiques
const commonIgnore = {
  atrule: ['@font-face'],
  decl: (node, value) => value.includes('url('),
  rule: [/modal-/, /dropdown-/, /js-/]
};

async function generateCriticalCss() {
  console.log('🚀 [Critical CSS] Début de l\'extraction...');

  try {
    // 1. PAGE D'ACCUEIL (FRONTPAGE)
    console.log('⏳ Analyse de la page d\'accueil...');
    await critical.generate({
      base: './',
      src: 'http://senelutte.ddev.site/',
      target: 'critical-css/front.css',
      dimensions: viewports,
      inline: false,
      extract: false,
      ignore: commonIgnore
    });
    console.log('✅ Fichier critical-css/front.css généré avec succès !');

    // 2. PAGE INTÉRIEURE (PROFIL LUTTEUR)
    console.log('⏳ Analyse d\'une page intérieure (Profil Sa Thiès)...');
    await critical.generate({
      base: './',
      src: 'http://senelutte.ddev.site/fr/lutteurs/sa-thies/7241',
      target: 'critical-css/default-critical.css',
      dimensions: viewports,
      inline: false,
      extract: false,
      ignore: commonIgnore
    });
    console.log('✅ Fichier critical-css/default-critical.css généré avec succès !');

    console.log('\n🎉 Terminé ! Tous les fichiers CSS critiques sont à jour.');

  } catch (error) {
    console.error('❌ Une erreur est survenue pendant la génération :', error);
  }
}

// Lancement du script
generateCriticalCss();