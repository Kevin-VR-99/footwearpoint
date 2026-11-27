/**
 * Editor de horario público: chips de días, rangos, plantillas y vista previa.
 * Se registra como Alpine.data('fpHorarioPublico') desde app.js.
 */
export default function fpHorarioPublico(config = {}) {
    return {
        dias: ['lun', 'mar', 'mie', 'jue', 'vie', 'sab', 'dom'],
        chips: { lun: 'L', mar: 'M', mie: 'X', jue: 'J', vie: 'V', sab: 'S', dom: 'D' },
        etiquetas: { lun: 'Lun', mar: 'Mar', mie: 'Mié', jue: 'Jue', vie: 'Vie', sab: 'Sáb', dom: 'Dom' },
        grupos: Array.isArray(config.grupos) ? JSON.parse(JSON.stringify(config.grupos)) : [],
        textoLibre: config.textoLibre || '',
        modo: config.modo || 'editor',
        propGrupos: config.propGrupos || 'horarios_publicos',
        propTexto: config.propTexto || 'horario_publico',
        errores: [],

        init() {
            if (this.modo === 'texto' && this.textoLibre && this.gruposVacios()) {
                this.modo = 'texto';
            } else {
                this.modo = 'editor';
                if (this.grupos.length === 0) {
                    this.grupos = [this.grupoVacio()];
                }
            }
            this.sincronizar();
        },

        gruposVacios() {
            return !this.grupos.some((g) => (g.dias && g.dias.length) || (g.rangos && g.rangos.length) || g.cerrado);
        },

        grupoVacio() {
            return { dias: [], rangos: [['09:00', '18:00']], cerrado: false };
        },

        agregarGrupo() {
            this.grupos.push(this.grupoVacio());
            this.alCambiar();
        },

        quitarGrupo(indice) {
            this.grupos.splice(indice, 1);
            if (this.grupos.length === 0) {
                this.grupos = [this.grupoVacio()];
            }
            this.alCambiar();
        },

        tieneDia(grupo, dia) {
            return Array.isArray(grupo.dias) && grupo.dias.includes(dia);
        },

        diaEnOtroGrupo(indice, dia) {
            return this.grupos.some((g, i) => i !== indice && this.tieneDia(g, dia));
        },

        alternarDia(indice, dia) {
            const grupo = this.grupos[indice];
            if (this.diaEnOtroGrupo(indice, dia)) {
                return;
            }
            const dias = Array.isArray(grupo.dias) ? [...grupo.dias] : [];
            const pos = dias.indexOf(dia);
            if (pos === -1) {
                dias.push(dia);
            } else {
                dias.splice(pos, 1);
            }
            grupo.dias = this.ordenarDias(dias);
            this.alCambiar();
        },

        ordenarDias(dias) {
            return this.dias.filter((d) => dias.includes(d));
        },

        alternarCerrado(indice) {
            const grupo = this.grupos[indice];
            grupo.cerrado = !grupo.cerrado;
            if (grupo.cerrado) {
                grupo.rangos = [];
            } else if (!grupo.rangos || grupo.rangos.length === 0) {
                grupo.rangos = [['09:00', '18:00']];
            }
            this.alCambiar();
        },

        agregarRango(indice) {
            const grupo = this.grupos[indice];
            if (!Array.isArray(grupo.rangos)) {
                grupo.rangos = [];
            }
            grupo.rangos.push(['16:00', '20:00']);
            this.alCambiar();
        },

        quitarRango(indice, rango) {
            const grupo = this.grupos[indice];
            grupo.rangos.splice(rango, 1);
            if (grupo.rangos.length === 0 && !grupo.cerrado) {
                grupo.rangos = [['09:00', '18:00']];
            }
            this.alCambiar();
        },

        aplicarPlantilla(clave) {
            const plantillas = {
                lv_9_18: [
                    { dias: ['lun', 'mar', 'mie', 'jue', 'vie'], rangos: [['09:00', '18:00']], cerrado: false },
                    { dias: ['sab', 'dom'], rangos: [], cerrado: true },
                ],
                ls_9_19: [
                    { dias: ['lun', 'mar', 'mie', 'jue', 'vie', 'sab'], rangos: [['09:00', '19:00']], cerrado: false },
                    { dias: ['dom'], rangos: [], cerrado: true },
                ],
                '24_horas': [
                    { dias: ['lun', 'mar', 'mie', 'jue', 'vie', 'sab', 'dom'], rangos: [['00:00', '23:59']], cerrado: false },
                ],
            };
            if (plantillas[clave]) {
                this.grupos = JSON.parse(JSON.stringify(plantillas[clave]));
                this.modo = 'editor';
                this.alCambiar();
            }
        },

        rearmar() {
            this.modo = 'editor';
            if (this.gruposVacios()) {
                this.grupos = [this.grupoVacio()];
            }
            this.alCambiar();
        },

        volverATexto() {
            this.modo = 'texto';
            this.grupos = [];
            this.errores = [];
            this.sincronizarTextoLibre();
        },

        vistaPrevia() {
            if (this.modo === 'texto') {
                return this.textoLibre || '';
            }
            return this.formatear(this.grupos);
        },

        formatear(grupos) {
            const partes = [];
            grupos.forEach((grupo) => {
                if (!grupo.dias || grupo.dias.length === 0) {
                    return;
                }
                const dias = this.formatearDias(grupo.dias);
                if (grupo.cerrado) {
                    partes.push(`${dias} cerrado`);
                    return;
                }
                const rangos = Array.isArray(grupo.rangos) ? grupo.rangos : [];
                if (rangos.length === 1 && rangos[0][0] === '00:00' && rangos[0][1] === '23:59') {
                    partes.push(`${dias}, las 24 horas`);
                    return;
                }
                const horas = rangos
                    .filter((r) => r[0] && r[1])
                    .map((r) => `${this.horaCorta(r[0])}–${this.horaCorta(r[1])}`);
                if (horas.length) {
                    partes.push(`${dias} ${this.unir(horas)}`);
                }
            });
            return partes.join(' · ');
        },

        formatearDias(dias) {
            const ordenados = this.ordenarDias(dias);
            const etiquetas = ordenados.map((d) => this.etiquetas[d]);
            if (etiquetas.length === 1) {
                return etiquetas[0];
            }
            let consecutivos = true;
            for (let i = 1; i < ordenados.length; i += 1) {
                if (this.dias.indexOf(ordenados[i]) !== this.dias.indexOf(ordenados[i - 1]) + 1) {
                    consecutivos = false;
                    break;
                }
            }
            if (consecutivos) {
                return `${etiquetas[0]}–${etiquetas[etiquetas.length - 1]}`;
            }
            return this.unir(etiquetas);
        },

        unir(partes) {
            if (partes.length <= 1) {
                return partes[0] || '';
            }
            return `${partes.slice(0, -1).join(', ')} y ${partes[partes.length - 1]}`;
        },

        horaCorta(hora) {
            const m = /^(\d{2}):(\d{2})$/.exec(hora || '');
            if (!m) {
                return hora;
            }
            return `${Number(m[1])}:${m[2]}`;
        },

        validarCliente() {
            const errores = [];
            const usados = {};
            this.grupos.forEach((grupo, indice) => {
                const n = indice + 1;
                if (!grupo.dias || grupo.dias.length === 0) {
                    return;
                }
                grupo.dias.forEach((dia) => {
                    if (usados[dia]) {
                        errores.push(`${this.etiquetas[dia]} ya está en otro horario. Un día no puede estar en dos grupos.`);
                    }
                    usados[dia] = n;
                });
                if (grupo.cerrado) {
                    return;
                }
                const rangos = Array.isArray(grupo.rangos) ? grupo.rangos : [];
                const minutos = [];
                rangos.forEach((rango) => {
                    const inicio = this.aMinutos(rango[0]);
                    const fin = this.aMinutos(rango[1]);
                    if (inicio === null || fin === null) {
                        errores.push(`El horario ${n} tiene una hora incompleta o inválida.`);
                        return;
                    }
                    if (fin <= inicio) {
                        errores.push(`En el horario ${n}, la hora de fin debe ser mayor que la de inicio.`);
                        return;
                    }
                    minutos.push([inicio, fin]);
                });
                minutos.sort((a, b) => a[0] - b[0]);
                for (let i = 1; i < minutos.length; i += 1) {
                    if (minutos[i][0] < minutos[i - 1][1]) {
                        errores.push(`El horario ${n} tiene rangos que se traslapan.`);
                        break;
                    }
                }
            });
            this.errores = [...new Set(errores)];
            return this.errores.length === 0;
        },

        aMinutos(hora) {
            const m = /^(\d{1,2}):(\d{2})$/.exec(hora || '');
            if (!m) {
                return null;
            }
            const h = Number(m[1]);
            const min = Number(m[2]);
            if (h < 0 || h > 23 || min < 0 || min > 59) {
                return null;
            }
            return h * 60 + min;
        },

        alCambiar() {
            this.validarCliente();
            this.sincronizar();
        },

        sincronizar() {
            if (!this.$wire) {
                return;
            }
            if (this.modo === 'texto') {
                this.sincronizarTextoLibre();
                return;
            }
            this.$wire.set(this.propGrupos, JSON.parse(JSON.stringify(this.grupos)), false);
            this.$wire.set(this.propTexto, this.formatear(this.grupos) || '', false);
        },

        sincronizarTextoLibre() {
            if (!this.$wire) {
                return;
            }
            this.$wire.set(this.propGrupos, [], false);
            this.$wire.set(this.propTexto, this.textoLibre || '', false);
        },
    };
}
