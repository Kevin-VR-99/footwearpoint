@props([
    'url',
    'texto' => 'Compartir por WhatsApp',
    'tamano' => 'normal',
])

{{-- Abre WhatsApp con el mensaje ya escrito (wa.me). Nueva pestaña: el panel o la tienda se quedan donde estaban. --}}
<a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
   {{ $attributes->merge(['class' => 'fp-btn group gap-2 bg-[#25D366] text-white hover:bg-[#1ebe5b] '.($tamano === 'sm' ? 'fp-btn-sm' : '')]) }}
   data-compartir="whatsapp">
    <svg class="h-4 w-4 transition-transform duration-200 group-hover:scale-110 group-hover:-rotate-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 004.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0012.04 2zm5.8 14.13c-.24.68-1.42 1.3-1.95 1.34-.5.05-1.13.07-1.82-.11-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.8-4.17-4.94-4.36-.15-.19-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.26-.29.58-.36.77-.36h.55c.18 0 .42-.07.65.5.24.58.82 2 .89 2.15.07.14.12.31.02.5-.1.19-.15.31-.29.48-.15.17-.31.38-.44.51-.15.14-.3.3-.13.59.17.29.75 1.24 1.61 2 1.11.99 2.04 1.3 2.33 1.44.29.15.46.12.63-.07.17-.19.72-.84.92-1.13.19-.29.38-.24.65-.14.26.1 1.68.79 1.97.94.29.14.48.22.55.34.07.12.07.7-.17 1.38z"/>
    </svg>
    <span>{{ $texto }}</span>
</a>
