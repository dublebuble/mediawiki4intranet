#!/usr/bin/perl

unlink("extensions/CategoryTree/SubcatCat.i18n.php");
system("svn revert -R includes extensions/CategoryTree languages extensions/AnyWikiDraw extensions/MediaFunctions extensions/Cite skins/common extensions/DeleteBatch");
for my $i (glob "custisinstall/patches/Y-*")
{
    system("patch -p0 -t --no-backup-if-mismatch < $i");
}
