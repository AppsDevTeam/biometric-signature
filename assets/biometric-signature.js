//
// adt/biometric-signature - podpis na plátně se záznamem dynamické biometriky.
//
// signature_pad kreslí podpis a dává z něj PNG. Vedle něj běží
// SignatureBiometrics na stejném plátně a ukládá surový průběh psaní:
// souřadnice v čase, tlak, náklon a natočení pera, typ vstupu (pero / dotyk /
// myš). signature_pad body ředí (throttle, minDistance) a náklon nezná, proto
// vlastní posluchače pointer eventů včetně getCoalescedEvents() - u Apple
// Pencil až ~240 vzorků/s místo jednoho bodu za snímek. Z tohoto záznamu jde
// podpis ve sporu porovnat (grafologická expertiza), samotný PNG to neumožňuje.
//
// Server záznam zkontroluje a znormalizuje (ADT\BiometricSignature\SignatureCapture).
//
import SignaturePad from 'signature_pad';

/** Formát záznamu - server ho kontroluje (BiometricsNormalizer::VERSION) */
export const BIOMETRICS_VERSION = 1;

/** Pořadí hodnot v každém bodu - musí sedět s BiometricsNormalizer::FIELDS */
export const BIOMETRICS_FIELDS = ['x', 'y', 't', 'p', 'tx', 'ty', 'alt', 'az', 'tw', 'w', 'h'];

const round = (value, decimals) => {
	if (typeof value !== 'number' || !Number.isFinite(value)) {
		return null;
	}
	const factor = 10 ** decimals;
	return Math.round(value * factor) / factor;
};

export class SignatureBiometrics {
	constructor(canvas) {
		this.canvas = canvas;
		this.activePointerId = null;
		this.clear();

		this.handleDown = (event) => this.onDown(event);
		this.handleMove = (event) => this.onMove(event);
		this.handleUp = (event) => this.onUp(event);

		canvas.addEventListener('pointerdown', this.handleDown);
		// Move/up na window - tah může vyjet mimo plátno a signature_pad
		// poslouchá stejně, záznam tak sedí s tím, co se nakreslilo
		window.addEventListener('pointermove', this.handleMove);
		window.addEventListener('pointerup', this.handleUp);
		window.addEventListener('pointercancel', this.handleUp);
	}

	/** Odpojí posluchače (plátno zmizelo ze stránky) */
	destroy() {
		this.canvas.removeEventListener('pointerdown', this.handleDown);
		window.removeEventListener('pointermove', this.handleMove);
		window.removeEventListener('pointerup', this.handleUp);
		window.removeEventListener('pointercancel', this.handleUp);
	}

	clear() {
		this.strokes = [];
		this.currentStroke = null;
		this.timeOrigin = null;
		this.startedAt = null;
	}

	isEmpty() {
		return this.strokes.length === 0;
	}

	onDown(event) {
		// Stejná podmínka jako signature_pad - kreslí jen primární tlačítko / dotyk
		if (!event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) {
			return;
		}

		if (this.timeOrigin === null) {
			this.timeOrigin = event.timeStamp;
			this.startedAt = new Date().toISOString();
		}
		this.activePointerId = event.pointerId;
		this.currentStroke = {
			type: event.pointerType || 'unknown',
			points: [],
		};
		this.strokes.push(this.currentStroke);
		this.addPoint(event);
	}

	onMove(event) {
		if (this.currentStroke === null || event.pointerId !== this.activePointerId) {
			return;
		}

		// Coalesced eventy = všechny vzorky mezi dvěma snímky; prohlížeče bez
		// podpory (nebo s prázdným polem) vrací jen samotný event
		const samples = typeof event.getCoalescedEvents === 'function' ? event.getCoalescedEvents() : [];
		(samples.length ? samples : [event]).forEach((sample) => this.addPoint(sample));
	}

	onUp(event) {
		if (this.currentStroke === null || event.pointerId !== this.activePointerId) {
			return;
		}

		this.addPoint(event);
		this.currentStroke = null;
		this.activePointerId = null;
	}

	addPoint(event) {
		const rect = this.canvas.getBoundingClientRect();
		this.currentStroke.points.push([
			round(event.clientX - rect.left, 2),
			round(event.clientY - rect.top, 2),
			// ms od prvního dotyku; přesnost timeStamp prohlížeče omezují (~0,1-1 ms)
			round(event.timeStamp - this.timeOrigin, 2),
			// Tlak: pero skutečný 0-1, myš 0.5 při stisku, prst na iPadu konstanta
			round(event.pressure, 4),
			round(event.tiltX, 2),
			round(event.tiltY, 2),
			// altitudeAngle/azimuthAngle jen novější prohlížeče (Safari iPadOS) - jinak null
			round(event.altitudeAngle, 4),
			round(event.azimuthAngle, 4),
			round(event.twist, 2),
			// Rozměr kontaktní plochy (u prstu reálný, u pera typicky 1)
			round(event.width, 2),
			round(event.height, 2),
		]);
	}

	/** Celý záznam pro hidden input; null bez jediného tahu */
	toJSON() {
		if (this.isEmpty()) {
			return null;
		}

		const rect = this.canvas.getBoundingClientRect();
		return {
			version: BIOMETRICS_VERSION,
			startedAt: this.startedAt,
			canvas: {
				width: round(rect.width, 2),
				height: round(rect.height, 2),
				devicePixelRatio: window.devicePixelRatio || 1,
			},
			device: {
				userAgent: navigator.userAgent,
				maxTouchPoints: navigator.maxTouchPoints || 0,
				screen: { width: window.screen.width, height: window.screen.height },
			},
			fields: BIOMETRICS_FIELDS,
			strokes: this.strokes,
		};
	}
}

/**
 * Napojí podpis na plátno: signature_pad kreslí, SignatureBiometrics
 * zaznamenává a po každém tahu se do inputů zapíše PNG (data URI)
 * a biometrika (JSON). Inputy drží hodnotu průběžně, odeslání formuláře
 * tak nezávisí na pořadí submit listenerů.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {object} options
 * @param {HTMLInputElement} options.imageInput input pro PNG data URI
 * @param {HTMLInputElement} options.biometricsInput input pro biometrický záznam
 * @param {HTMLElement|null} [options.clearButton] tlačítko „Vymazat podpis“
 * @param {string|function(): string} [options.penColor] barva pera (funkce = načte se při napojení, např. z CSS proměnné)
 * @param {object} [options.padOptions] další volby signature_pad
 * @param {function(): void} [options.onChange] po každém tahu a po smazání
 * @returns {{pad: SignaturePad, biometrics: SignatureBiometrics, clear: function(): void, isEmpty: function(): boolean, destroy: function(): void}}
 */
export function attachBiometricSignature(canvas, { imageInput, biometricsInput, clearButton = null, penColor = null, padOptions = {}, onChange = null }) {
	const color = typeof penColor === 'function' ? penColor() : penColor;
	const pad = new SignaturePad(canvas, { ...padOptions, ...(color ? { penColor: color } : {}) });
	const biometrics = new SignatureBiometrics(canvas);

	const clear = () => {
		pad.clear();
		biometrics.clear();
		imageInput.value = '';
		biometricsInput.value = '';
		if (onChange) onChange();
	};

	// Plátno potřebuje fyzické rozměry podle devicePixelRatio, jinak je tah
	// posunutý vůči prstu. Změna velikosti plátno smaže (souřadnice biometriky
	// by neseděly s obrázkem), proto jen při skutečné změně rozměrů - mobilní
	// prohlížeče hlásí resize i při schování lišty s adresou nebo klávesnice.
	// ResizeObserver chytí i plátno, které bylo při napojení skryté (modal,
	// záložka, boční panel) a mělo nulovou velikost.
	let size = null;
	const resize = () => {
		const width = canvas.offsetWidth;
		const height = canvas.offsetHeight;
		if (size !== null && size.width === width && size.height === height) {
			return;
		}
		size = { width, height };
		const ratio = Math.max(window.devicePixelRatio || 1, 1);
		canvas.width = width * ratio;
		canvas.height = height * ratio;
		canvas.getContext('2d').scale(ratio, ratio);
		clear();
	};
	resize();
	const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(resize) : null;
	if (observer) {
		observer.observe(canvas);
	} else {
		window.addEventListener('resize', resize);
	}

	// endStroke signature_padu chodí z pointerup na window stejně jako náš
	// záznam - biometrika se proto zapisuje až v dalším ticku, aby obsahovala
	// i zvednutí pera
	const onEndStroke = () => {
		imageInput.value = pad.toDataURL('image/png');
		setTimeout(() => {
			const record = biometrics.toJSON();
			biometricsInput.value = record ? JSON.stringify(record) : '';
			if (onChange) onChange();
		});
	};
	pad.addEventListener('endStroke', onEndStroke);

	const onClearClick = (event) => {
		event.preventDefault();
		clear();
	};
	if (clearButton) {
		clearButton.addEventListener('click', onClearClick);
	}

	return {
		pad,
		biometrics,
		clear,
		isEmpty: () => pad.isEmpty(),
		destroy() {
			if (observer) {
				observer.disconnect();
			} else {
				window.removeEventListener('resize', resize);
			}
			pad.removeEventListener('endStroke', onEndStroke);
			if (clearButton) {
				clearButton.removeEventListener('click', onClearClick);
			}
			pad.off();
			biometrics.destroy();
		},
	};
}

/**
 * Napojí všechny podpisy v kontejneru podle data atributů:
 *
 *     <div data-biometric-signature>
 *         <canvas></canvas>
 *         <button type="button" data-biometric-signature-clear>Vymazat podpis</button>
 *         <input type="hidden" name="signatureImage" data-biometric-signature-image>
 *         <input type="hidden" name="signatureBiometrics" data-biometric-signature-biometrics>
 *     </div>
 *
 * Inputy a tlačítko se hledají uvnitř obalu, pak v nejbližším formuláři.
 * Už napojený obal se přeskočí, takže jde volat opakovaně (AJAX překreslení).
 *
 * @param {ParentNode} [root]
 * @param {object} [options] penColor, padOptions, onChange - viz attachBiometricSignature()
 * @returns {Array} napojené podpisy
 */
export function initBiometricSignatures(root = document, options = {}) {
	const attached = [];
	root.querySelectorAll('[data-biometric-signature]').forEach((wrapper) => {
		if (wrapper.biometricSignature) {
			return;
		}
		const form = wrapper.closest('form');
		const find = (selector) => wrapper.querySelector(selector) || (form ? form.querySelector(selector) : null);
		const canvas = wrapper.querySelector('canvas');
		const imageInput = find('[data-biometric-signature-image]');
		const biometricsInput = find('[data-biometric-signature-biometrics]');
		if (!canvas || !imageInput || !biometricsInput) {
			return;
		}

		wrapper.biometricSignature = attachBiometricSignature(canvas, {
			...options,
			imageInput,
			biometricsInput,
			clearButton: find('[data-biometric-signature-clear]'),
		});
		attached.push(wrapper.biometricSignature);
	});

	return attached;
}
