<?php

use App\Support\Ansi;

test('plain text is escaped and kept as it is', function () {
    expect(Ansi::toHtml("<b>a</b> & b\nc")->toHtml())->toBe("&lt;b&gt;a&lt;/b&gt; &amp; b\nc");
});

test('colours and bold become styled spans and a reset ends them', function () {
    $html = Ansi::toHtml("\e[37;44m INFO \e[39;49m Discovering. \e[32;1mDONE\e[39;22m")->toHtml();

    expect($html)->toBe('<span style="color:#e4e4e7;background-color:#2563eb"> INFO </span> Discovering. <span style="color:#4ade80;font-weight:700">DONE</span>');
});

test('runs of the same style merge into one span', function () {
    expect(Ansi::toHtml("\e[90m.\e[39m\e[90m.\e[39m\e[90m.\e[39m")->toHtml())->toBe('<span style="color:#71717a">...</span>');
});

test('git diff colours, 256 and true colour are understood', function () {
    expect(Ansi::toHtml("\e[1mdiff\e[m\n\e[31m-old\e[m\n\e[32m+new\e[m")->toHtml())
        ->toBe("<span style=\"font-weight:700\">diff</span>\n<span style=\"color:#f87171\">-old</span>\n<span style=\"color:#4ade80\">+new</span>")
        ->and(Ansi::toHtml("\e[38;5;196mx")->toHtml())->toBe('<span style="color:#ff0000">x</span>')
        ->and(Ansi::toHtml("\e[38;2;1;2;3mx")->toHtml())->toBe('<span style="color:#010203">x</span>');
});

test('cursor, erase and hyperlink sequences are dropped, a carriage return rewrites the line', function () {
    $html = Ansi::toHtml("\e[2K\e[1Gloading 10%\rloading 100%\n\e]8;;https://x.test\e\\link\e]8;;\e\\ ok")->toHtml();

    expect($html)->toBe("loading 100%\nlink ok");
});

test('a sequence cut off at the end of a running output is not shown', function () {
    expect(Ansi::toHtml("done \e[3")->toHtml())->toBe('done ');
});
