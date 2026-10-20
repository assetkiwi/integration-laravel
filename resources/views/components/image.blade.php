@if ($src)
    <img
        src="{{ $src }}"
        @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
        alt="{{ $altText }}"
        @if ($imgWidth)  width="{{ $imgWidth }}"   @endif
        @if ($imgHeight) height="{{ $imgHeight }}"  @endif
        @if ($class)     class="{{ $class }}"        @endif
    />
@endif
