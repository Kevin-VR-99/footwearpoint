@props(['variante' => 'completo'])
{{-- Logo OFICIAL de Mercado Pago (archivos servidos localmente desde public/img/mercadopago):
     - completo: logotipo horizontal a color (manos + "mercado pago"), proporción 284×74.
       Origen: https://http2.mlstatic.com/frontend-assets/mp-web-navigation/ui-navigation/7.8.2/mercadopago/logo__large@2x.png
     - icono: solo el apretón de manos, para tamaños pequeños.
       Origen: https://http2.mlstatic.com/frontend-assets/dx-template-lib/assets/logo-mercadopago.svg
     Fijar solo la altura (h-*) y dejar w-auto para no deformarlo. --}}
@if ($variante === 'icono')
    <img src="{{ asset('img/mercadopago/icono.svg') }}" width="32" height="32" draggable="false"
        {{ $attributes->except('class')->merge(['alt' => 'Mercado Pago'])->class([$attributes->get('class', 'h-6 w-auto'), 'shrink-0 object-contain']) }}>
@else
    <img src="{{ asset('img/mercadopago/logo.png') }}" width="284" height="74" draggable="false"
        {{ $attributes->except('class')->merge(['alt' => 'Mercado Pago'])->class([$attributes->get('class', 'h-8 w-auto'), 'shrink-0 object-contain']) }}>
@endif
