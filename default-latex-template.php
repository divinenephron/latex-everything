\documentclass[12pt]{article}
\usepackage{fullpage}
\usepackage{graphicx}
\usepackage{url}
\setlength{\parskip}{1ex}
\setlength{\parindent}{0ex}

% Note that there isn't a loop, just the_post()
% This is because Latex isn't designed to typset multiple articles at once.
% This template typesets a single post, then multiple PDFs will be stitched
% together by the plugin, so please don't try to make The Loop.
<?php the_post() ?>

% Note I've used the lt() function.
% The same job is done by latex_text() and get_latex_text() (which returns
% a string rather than echoing it).
% This function performs substitutions to make text pretty and suitable for
% inclusion in macro arguments. If you don't wrap your output in it you
% might get errors.
\date{<?php lt( get_the_date() ) ?>}
\title{<?php lt( get_the_title() ) ?>}
\author{<?php lt( get_the_author() ) ?>}

\begin{document}

\maketitle

% Note the use of the h2l() function.
% The same job is done by html_to_latex() and get_html_to_latex() (which
% returns a string rather than echoing it).
% This converts html into Latex equivalents, and can be found in
% html-to-latex.php.
<?php h2l( apply_filters( 'the_content', get_the_content() ) ) ?>

% I've only tested this templating with string output functions such as
% those shown above. I doubt other functions, most notably the is_something()
% functions will work correctly.
\end{document}
