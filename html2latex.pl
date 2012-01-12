# Usage: html2latex.pl [html input file] [blog url] [path to wordpress]
# Converts the given html file into LaTeX then
# prints it to stdout.

# Use the local HTML2Latex library
use FindBin;
use lib "$FindBin::Bin/perl5"; 
use HTML::Latex;

# Create an HTML2Latex parser.
my $parser = new HTML::Latex();
$parser->set_option({store => './html2latex'});

# Read from the provided HTML file, and ouput to a tex file of the same name.
my $html_filename = @ARGV[0];
open HTML, "<", $html_filename or die $!;
my $html = do { local( $/ ); <HTML> } ;

# Convert html to tex (without a preamble).
my $tex = $parser->parse_string($html);

print $tex;

