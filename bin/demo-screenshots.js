/**
 * Macht aus den Demo-Seiten (bin/demo-screenshots.php) die PNGs, die im README
 * stehen. Playwright kommt aus den Dev-Abhaengigkeiten von @wordpress/scripts,
 * eine eigene Installation braucht es nicht:
 *
 *   php bin/demo-screenshots.php
 *   node bin/demo-screenshots.js
 *
 * Zwei Eigenheiten, die Zeit gekostet haben und deshalb hier stehen:
 * locator.screenshot() wartet auf einen "stabilen" Zustand und laeuft dabei in
 * Seiten mit Dauer-Animationen in einen Timeout - deshalb page.screenshot() mit
 * selbst gerechnetem Ausschnitt. Und der offene <dialog> des Popups liegt fest
 * im Sichtfenster, wird also ohne fullPage aufgenommen, waehrend die uebrigen
 * Abschnitte Seitenkoordinaten brauchen.
 */

const path = require('path');
const { chromium } = require('playwright');

const repo = path.resolve(__dirname, '..');
const build = path.join(repo, 'docs', '.demo');
const out = path.join(repo, 'docs', 'screenshots');

const abschnitte = ['liste', 'grid', 'naechster-termin', 'eventfinder'];

/*
 * 1.3 statt 2: Die Bilder liegen im Repo und stehen im README, brauchen dort
 * also keine volle Retina-Aufloesung - 1.3 ergibt rund 1400 Pixel Breite,
 * scharf genug und je Bild ein paar hundert Kilobyte statt eines Megabyte.
 */
const scale = 1.3;

(async () => {
	const browser = await chromium.launch();

	const page = await browser.newPage({ viewport: { width: 1180, height: 900 }, deviceScaleFactor: scale });
	await page.goto('file://' + path.join(build, 'demo.html'), { waitUntil: 'networkidle' });
	await page.waitForTimeout(500);

	for (const id of abschnitte) {
		const clip = await page.evaluate((sel) => {
			const el = document.querySelector('#' + sel + ' .ctp-events');
			if (!el) {
				return null;
			}
			const box = el.getBoundingClientRect();
			return {
				x: box.left + scrollX - 24,
				y: box.top + scrollY - 24,
				width: box.width + 48,
				height: box.height + 48,
			};
		}, id);

		if (!clip) {
			console.log(id + ': Abschnitt nicht gefunden');
			continue;
		}

		await page.screenshot({ path: path.join(out, id + '.png'), clip, fullPage: true });
		console.log(id + '.png');
	}

	const popupPage = await browser.newPage({ viewport: { width: 900, height: 1000 }, deviceScaleFactor: scale });
	await popupPage.goto('file://' + path.join(build, 'demo-popup.html'), { waitUntil: 'networkidle' });
	await popupPage.waitForTimeout(400);
	const box = await popupPage.evaluate(() => {
		const rect = document.querySelector('.ctp-events__modal').getBoundingClientRect();
		return { x: rect.left - 20, y: rect.top - 20, width: rect.width + 40, height: rect.height + 40 };
	});
	await popupPage.screenshot({ path: path.join(out, 'popup.png'), clip: box });
	console.log('popup.png');

	await browser.close();
	console.log('\nBilder liegen in docs/screenshots/.');
})();
