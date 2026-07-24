/*
 * /home/myzonaco/smartpark.myzona360.com/assets/js/sp_placa.js
 *
 * Validador y corrector de placas Colombia.
 *   - Carro:    ABC123  (3 letras + 3 números)
 *   - Moto:     ABC12D  (3 letras + 2 números + 1 letra)
 *   - Antigua:  AB1234  (2 letras + 4 números) — algunas viejas
 *
 * Corrige errores comunes de OCR según POSICIÓN:
 *   - En posiciones de letra: 0 → O, 1 → I, 5 → S, 8 → B, 2 → Z
 *   - En posiciones de número: O → 0, I → 1, S → 5, B → 8, Z → 2
 */

window.SPPlaca = (function () {

    function limpiar(raw) {
        return String(raw || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    // Mapas de corrección
    var letraDeNumero = { '0':'O','1':'I','5':'S','8':'B','2':'Z' };
    var numeroDeLetra = { 'O':'0','I':'1','S':'5','B':'8','Z':'2','Q':'0','G':'6','D':'0' };

    /**
     * Detecta tipo y corrige según patrón colombiano.
     * Retorna { placa, tipo, valida, original, correcciones }
     */
    function validarYCorregir(raw) {
        var original = limpiar(raw);
        if (original.length < 5) {
            return { placa: original, tipo: '?', valida: false, original: original, correcciones: [] };
        }

        // Probar formato CARRO (3L + 3N)
        if (original.length === 6) {
            var resCarro = forzarFormato(original, 'LLLNNN');
            if (resCarro) {
                return { placa: resCarro.placa, tipo: 'carro', valida: true,
                    original: original, correcciones: resCarro.correcciones };
            }
            // Probar AB1234 (2L + 4N) - placas viejas
            var resAntigua = forzarFormato(original, 'LLNNNN');
            if (resAntigua) {
                return { placa: resAntigua.placa, tipo: 'carro', valida: true,
                    original: original, correcciones: resAntigua.correcciones };
            }
        }

        // Probar formato MOTO (3L + 2N + 1L)
        if (original.length === 6) {
            var resMoto = forzarFormato(original, 'LLLNNL');
            if (resMoto) {
                return { placa: resMoto.placa, tipo: 'moto', valida: true,
                    original: original, correcciones: resMoto.correcciones };
            }
        }

        // No coincidió con ningún patrón válido
        return { placa: original, tipo: '?', valida: false, original: original, correcciones: [] };
    }

    /**
     * Intenta forzar una cadena a un formato dado.
     * patron: cadena con 'L' para letra y 'N' para número.
     * Retorna { placa, correcciones } si logró, null si no.
     */
    function forzarFormato(cadena, patron) {
        if (cadena.length !== patron.length) return null;
        var resultado = '';
        var correcciones = [];

        for (var i = 0; i < patron.length; i++) {
            var ch = cadena[i];
            var esperado = patron[i];

            if (esperado === 'L') {
                if (/[A-Z]/.test(ch)) {
                    resultado += ch;
                } else if (letraDeNumero[ch]) {
                    resultado += letraDeNumero[ch];
                    correcciones.push({ pos: i, de: ch, a: letraDeNumero[ch] });
                } else {
                    return null; // no se pudo corregir
                }
            } else { // 'N'
                if (/[0-9]/.test(ch)) {
                    resultado += ch;
                } else if (numeroDeLetra[ch]) {
                    resultado += numeroDeLetra[ch];
                    correcciones.push({ pos: i, de: ch, a: numeroDeLetra[ch] });
                } else {
                    return null;
                }
            }
        }
        return { placa: resultado, correcciones: correcciones };
    }

    /**
     * Aplica solo limpieza básica sin forzar formato (para input manual del rondero).
     */
    function soloLimpiar(raw) {
        return limpiar(raw);
    }

    return {
        validarYCorregir: validarYCorregir,
        soloLimpiar: soloLimpiar,
        limpiar: limpiar,
    };
})();
