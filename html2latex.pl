# Use the local HTML2Latex library
use FindBin;
use lib "$FindBin::Bin/perl5"; 
use HTML::Latex;

# Create an HTML2Latex parser
my $parser = new HTML::Latex();

# Read from the provided HTML file, and ouput to a tex file of the same name.
my $html_filename = $tex_filename = @ARGV[0];
$tex_filename =~ s/\.[^\/]+$/.tex/;
$parser->html2latex($html_filename,$tex_filename);
